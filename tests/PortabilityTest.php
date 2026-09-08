<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Ebay\Auth\StaticAccessTokenProvider as EbayToken;
use Survos\Ebay\Generated\Commerce\Taxonomy\TaxonomyApi;
use Survos\Ebay\Generated\Sell\Inventory\InventoryApi;
use Survos\Ebay\Http\EbayTransport;
use Survos\MarketplaceBundle\Adapter\EbayAdapter;
use Survos\MarketplaceBundle\Adapter\MercadoLibreAdapter;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Model\ListingCondition;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingImage;
use Survos\MarketplaceContracts\Model\Money;
use Survos\MarketplaceContracts\Model\ProviderCapability;
use Survos\MarketplaceContracts\Model\ViolationCode;
use Survos\MercadoLibre\Api\CategoriesApi;
use Survos\MercadoLibre\Api\ItemsApi;
use Survos\MercadoLibre\Auth\StaticAccessTokenProvider as MeliToken;
use Survos\MercadoLibre\Http\MercadoLibreTransport;
use Survos\MercadoLibre\MercadoLibreSite;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The point of the abstraction: one draft, two marketplaces.
 *
 * These tests exist to catch the abstraction leaking -- a draft that only works on
 * the provider it was written against is an abstraction that has not earned its
 * keep.
 */
final class PortabilityTest extends TestCase
{
    /** @param list<MockResponse> $responses */
    private function ebay(array $responses): EbayAdapter
    {
        $transport = new EbayTransport(new MockHttpClient($responses), new EbayToken('tok'));

        return new EbayAdapter(
            inventory: new InventoryApi($transport),
            taxonomy: new TaxonomyApi($transport),
            merchantLocationKey: 'SAN_CRISTOBAL',
            fulfillmentPolicyId: 'FP-1',
            paymentPolicyId: 'PP-1',
            returnPolicyId: 'RP-1',
        );
    }

    /** @param list<MockResponse> $responses */
    private function meli(array $responses): MercadoLibreAdapter
    {
        $transport = new MercadoLibreTransport(new MockHttpClient($responses), new MeliToken('tok', 1));

        return new MercadoLibreAdapter(
            items: new ItemsApi($transport),
            categories: new CategoriesApi($transport, MercadoLibreSite::Mexico),
        );
    }

    private function draft(string $currency = 'USD'): ListingDraft
    {
        return new ListingDraft(
            sku: 'PC-ANIMALS-001',
            title: 'Lot of 5 Vintage Animal Postcards',
            description: 'Five themed postcards, 1930s-1950s.',
            price: Money::fromDecimal('4.00', $currency),
            condition: ListingCondition::UsedVeryGood,
            images: [new ListingImage('https://example.org/animals.jpg')],
        );
    }

    public function testBothAdaptersSatisfyTheInterface(): void
    {
        self::assertInstanceOf(MarketplaceAdapterInterface::class, $this->ebay([]));
        self::assertInstanceOf(MarketplaceAdapterInterface::class, $this->meli([]));
    }

    public function testCapabilitiesDivergeHonestly(): void
    {
        // eBay has a sandbox; Mercado Libre does not. Callers branch on this
        // rather than on the provider name.
        self::assertTrue($this->ebay([])->supports(ProviderCapability::Sandbox));
        self::assertFalse($this->meli([])->supports(ProviderCapability::Sandbox));

        // eBay needs policies and a location first; Mercado Libre needs nothing.
        self::assertTrue($this->ebay([])->supports(ProviderCapability::SellerBootstrap));
        self::assertFalse($this->meli([])->supports(ProviderCapability::SellerBootstrap));
    }

    public function testTheTighterTitleCapIsTheOneThatMatters(): void
    {
        // A title generated once for both must fit the smaller limit. Mercado
        // Libre's 60 is what constrains a portable draft, not eBay's 80.
        self::assertSame(80, $this->ebay([])->limits()->titleMaxLength);
        self::assertSame(60, $this->meli([])->limits()->titleMaxLength);

        $draft = $this->draft()->withTitle(str_repeat('a', 70))->withCategoryId('1');

        self::assertSame([], $draft->violations($this->ebay([])->limits()));

        $codes = array_map(
            static fn ($v) => $v->code,
            $draft->violations($this->meli([])->limits()),
        );
        self::assertContains(ViolationCode::TitleTooLong, $codes);
    }

    public function testBothNormalizeCategorySuggestionsToTheSameShape(): void
    {
        $ebay = $this->ebay([
            new MockResponse('{"categoryTreeId":"0"}'),
            new MockResponse((string) json_encode(['categorySuggestions' => [[
                'category' => ['categoryId' => '914', 'categoryName' => 'Postcards'],
                'categoryTreeNodeAncestors' => [
                    ['categoryName' => 'Postcards'],
                    ['categoryName' => 'Collectibles'],
                ],
                'relevancy' => '85%',
            ]]])),
        ])->suggestCategories('vintage animal postcards');

        $meli = $this->meli([new MockResponse((string) json_encode([[
            'domain_id' => 'MLM-COLLECTIBLE_POSTCARDS',
            'domain_name' => 'Tarjetas postales coleccionables',
            'category_id' => 'MLM429001',
            'category_name' => 'Postales',
        ]]))])->suggestCategories('postal antigua');

        self::assertSame('914', $ebay[0]->categoryId);
        self::assertSame('MLM429001', $meli[0]->categoryId);

        // eBay reports relevancy as "85%"; the neutral model normalizes to 0..1.
        self::assertSame(0.85, $ebay[0]->confidence);
        // Mercado Libre reports none, and the adapter does not invent one.
        self::assertNull($meli[0]->confidence);

        // Ancestors arrive leaf-first from eBay and must read root-first.
        self::assertSame('Collectibles > Postcards > Postcards', $ebay[0]->breadcrumb());
    }

    public function testBothNormalizeRequirednessDespiteEncodingItDifferently(): void
    {
        // eBay: aspectConstraint.aspectRequired, values under aspectValues.
        $ebaySchema = $this->ebay([
            new MockResponse('{"categoryTreeId":"0"}'),
            new MockResponse((string) json_encode(['aspects' => [
                [
                    'localizedAspectName' => 'Era',
                    'aspectConstraint' => [
                        'aspectRequired' => true,
                        'aspectDataType' => 'STRING',
                        'itemToAspectCardinality' => 'SINGLE',
                    ],
                    'aspectValues' => [['localizedValue' => 'Pre-1914'], ['localizedValue' => 'Post-1945']],
                ],
                [
                    'localizedAspectName' => 'Theme',
                    'aspectConstraint' => [
                        'aspectRequired' => false,
                        'aspectDataType' => 'STRING',
                        'itemToAspectCardinality' => 'MULTI',
                    ],
                ],
            ]])),
        ])->attributeSchema('914');

        // Mercado Libre: tags.required, values under values[], plus read_only /
        // hidden entries that must never reach a listing.
        $meliSchema = $this->meli([new MockResponse((string) json_encode([
            [
                'id' => 'ORIGIN', 'name' => 'Origen', 'value_type' => 'string',
                'tags' => ['required' => true],
                'values' => [['id' => '2114722', 'name' => 'España']],
            ],
            [
                'id' => 'DESCRIPTIVE_TAGS', 'name' => 'Etiquetas', 'value_type' => 'list',
                'tags' => ['multivalued' => true],
            ],
            [
                'id' => 'SAT_KEY', 'name' => 'Clave SAT', 'value_type' => 'string',
                'tags' => ['read_only' => true, 'required' => true],
            ],
        ]))])->attributeSchema('MLM429001');

        self::assertSame(['Era'], $ebaySchema->requiredNames());
        self::assertSame(['ORIGIN'], $meliSchema->requiredNames());

        // The read-only attribute is required upstream but unsettable, so it must
        // not appear at all -- otherwise every draft is permanently invalid.
        self::assertNull($meliSchema->get('SAT_KEY'));
        self::assertCount(2, $meliSchema);

        // Cardinality survives both encodings.
        self::assertTrue($ebaySchema->get('Theme')?->isMultiValued());
        self::assertTrue($meliSchema->get('DESCRIPTIVE_TAGS')?->isMultiValued());
        self::assertFalse($ebaySchema->get('Era')?->isMultiValued());

        // And so does the closed value set, which is what the AI loop needs.
        self::assertSame(['Pre-1914', 'Post-1945'], $ebaySchema->get('Era')?->allowedValues);
        self::assertSame(['España'], $meliSchema->get('ORIGIN')?->allowedValues);
    }

    public function testTheSameDraftPublishesToBothProviders(): void
    {
        $draft = $this->draft()->withCategoryId('914')->withAttributes(['Era' => 'Pre-1914']);

        $ebayResult = $this->ebay([
            new MockResponse('{}', ['http_code' => 204]),                  // createOrReplaceInventoryItem
            new MockResponse('{"offerId":"offer-42"}'),                    // createOffer
            new MockResponse('{"listingId":"110586523456"}'),              // publishOffer
        ])->publish($draft);

        self::assertSame('ebay', $ebayResult->provider);
        self::assertSame('offer-42', $ebayResult->externalId, 'the offer id is the actionable handle');
        self::assertSame('110586523456', $ebayResult->raw['listingId']);
        self::assertSame('https://www.ebay.com/itm/110586523456', $ebayResult->url);

        $meliResult = $this->meli([
            new MockResponse('[]'),                                        // writableAttributes lookup
            new MockResponse((string) json_encode([
                'id' => 'MLM1234567890',
                'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-1234567890',
                'status' => 'active',
            ])),
        ])->publish($draft->withPrice(Money::fromDecimal('80.00', 'MXN')));

        self::assertSame('mercadolibre', $meliResult->provider);
        self::assertSame('MLM1234567890', $meliResult->externalId);
        self::assertSame('PC-ANIMALS-001', $meliResult->sku);
    }

    public function testEbayRefusesToPublishWithoutSellerBootstrapAndSaysWhatIsMissing(): void
    {
        $transport = new EbayTransport(new MockHttpClient([]), new EbayToken('tok'));
        $adapter = new EbayAdapter(new InventoryApi($transport), new TaxonomyApi($transport));

        try {
            $adapter->publish($this->draft()->withCategoryId('914'));
            self::fail('expected rejection');
        } catch (\Survos\MarketplaceContracts\Exception\ListingRejectedException $e) {
            self::assertCount(4, $e->violations);
            self::assertStringContainsString('merchant_location_key', $e->getMessage());
            // eBay's own error for this names neither the policy nor the fix.
            self::assertStringContainsString('business policies', (string) $e->violations[0]);
        }
    }

    public function testConditionCoarseningIsLossyAndOneWay(): void
    {
        // eBay keeps all eight; Mercado Libre has three. "Used - very good" and
        // "For parts" both collapse to `used`, and the nuance has to live in the
        // description instead.
        $seen = '';
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            if (str_contains($u, '/items')) {
                $seen = (string) $o['body'];
            }

            return new MockResponse(str_contains($u, '/items') ? '{"id":"MLM1"}' : '[]');
        });

        $adapter = new MercadoLibreAdapter(
            items: new ItemsApi(new MercadoLibreTransport($client, new MeliToken('t'))),
            categories: new CategoriesApi(new MercadoLibreTransport($client), MercadoLibreSite::Mexico),
        );

        $adapter->publish($this->draft('MXN')->withCategoryId('MLM429001'));

        $payload = json_decode($seen, true);
        self::assertSame('used', $payload['condition']);
    }
}
