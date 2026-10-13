<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\Ebay\Generated\Commerce\Taxonomy\Model\Aspect;
use Survos\Ebay\Generated\Commerce\Taxonomy\Model\AspectValue;
use Survos\Ebay\Generated\Commerce\Taxonomy\TaxonomyApi;
use Survos\Ebay\Generated\Sell\Inventory\InventoryApi;
use Survos\Ebay\Generated\Sell\Inventory\Model as Ebay;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\ListingRejectedException;
use Survos\MarketplaceContracts\Model\AttributeDefinition;
use Survos\MarketplaceContracts\Model\AttributeSchema;
use Survos\MarketplaceContracts\Model\AttributeValueType;
use Survos\MarketplaceContracts\Model\CategorySuggestion;
use Survos\MarketplaceContracts\Model\ListingCondition;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingFormat;
use Survos\MarketplaceContracts\Model\ListingImage;
use Survos\MarketplaceContracts\Model\ListingViolation;
use Survos\MarketplaceContracts\Model\MarketplaceLimits;
use Survos\MarketplaceContracts\Model\ProviderCapability;
use Survos\MarketplaceContracts\Model\PublishedListing;
use Survos\MarketplaceContracts\Model\ViolationCode;

/**
 * eBay, behind the provider-neutral interface.
 *
 * Publishing is three calls, not one: an inventory item keyed by SKU, an offer
 * against it, then a publish. All three are hidden here -- but the *prerequisites*
 * are not hideable. An offer cannot be created without a merchant location key and
 * three business policy ids, which the seller must have created beforehand. They
 * arrive as connection options and are validated up front, because the error eBay
 * returns for a missing policy names neither the policy nor the fix.
 */
final class EbayAdapter implements MarketplaceAdapterInterface
{
    private ?string $categoryTreeId = null;

    /**
     * @param string $merchantLocationKey created once per seller via createInventoryLocation
     * @param string $fulfillmentPolicyId shipping; where ShippingSpec ultimately lands
     */
    public function __construct(
        private readonly InventoryApi $inventory,
        private readonly TaxonomyApi $taxonomy,
        private readonly string $marketplaceId = 'EBAY_US',
        private readonly ?string $merchantLocationKey = null,
        private readonly ?string $fulfillmentPolicyId = null,
        private readonly ?string $paymentPolicyId = null,
        private readonly ?string $returnPolicyId = null,
    ) {
    }

    public function provider(): string
    {
        return 'ebay';
    }

    public function site(): string
    {
        return $this->marketplaceId;
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::Sandbox,
            ProviderCapability::CategorySuggestion,
            ProviderCapability::AttributeSchema,
            ProviderCapability::Withdraw,
            ProviderCapability::FlatShipping,
            ProviderCapability::CalculatedShipping,
            ProviderCapability::SellerBootstrap,
        ];
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function limits(): MarketplaceLimits
    {
        // 80 is the one that bites: a model asked for "a good listing title"
        // produces 140 without blinking.
        return new MarketplaceLimits(titleMaxLength: 80, maxImages: 24);
    }

    public function suggestCategories(string $title, int $limit = 5): array
    {
        $response = $this->taxonomy->getCategorySuggestions($this->categoryTreeId(), $title);

        $suggestions = [];
        foreach ($response->categorySuggestions ?? [] as $suggestion) {
            $category = $suggestion->category ?? null;
            if (null === $category?->categoryId) {
                continue;
            }

            // Ancestors come back leaf-first; a breadcrumb reads root-first.
            $path = [];
            foreach (array_reverse($suggestion->categoryTreeNodeAncestors ?? []) as $ancestor) {
                if (null !== $ancestor->categoryName) {
                    $path[] = $ancestor->categoryName;
                }
            }
            $path[] = (string) $category->categoryName;

            $suggestions[] = new CategorySuggestion(
                categoryId: $category->categoryId,
                name: (string) $category->categoryName,
                path: $path,
                // eBay's relevancy is a percentage string like "85%", not a float.
                confidence: $this->parseRelevancy($suggestion->relevancy),
            );

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    public function attributeSchema(string $categoryId): AttributeSchema
    {
        $metadata = $this->taxonomy->getItemAspectsForCategory($this->categoryTreeId(), $categoryId);

        $definitions = [];
        foreach ($metadata->aspects ?? [] as $aspect) {
            if (!$aspect instanceof Aspect || null === $aspect->localizedAspectName) {
                continue;
            }

            $constraint = $aspect->aspectConstraint;

            $values = [];
            foreach ($aspect->aspectValues ?? [] as $value) {
                if ($value instanceof AspectValue && null !== $value->localizedValue) {
                    $values[] = $value->localizedValue;
                }
            }

            $definitions[] = new AttributeDefinition(
                name: $aspect->localizedAspectName,
                // Unlike the OpenAPI schema -- which declares no required fields
                // anywhere -- the runtime response does carry requiredness.
                required: (bool) ($constraint?->aspectRequired ?? false),
                valueType: match (strtoupper((string) ($constraint?->aspectDataType ?? 'STRING'))) {
                    'NUMBER' => AttributeValueType::Number,
                    'DATE' => AttributeValueType::Date,
                    default => AttributeValueType::String,
                },
                // aspectMode says whether the values are a menu or a hint.
                // FREE_TEXT aspects still return sample values, and treating those
                // as an enum boxes a model into eBay's examples.
                allowedValues: 'SELECTION_ONLY' === strtoupper((string) ($constraint?->aspectMode ?? ''))
                    ? $values
                    : [],
                // MULTI means unlimited; anything else is single-valued.
                maxValues: 'MULTI' === strtoupper((string) ($constraint?->itemToAspectCardinality ?? 'SINGLE'))
                    ? null
                    : 1,
                examples: 'SELECTION_ONLY' === strtoupper((string) ($constraint?->aspectMode ?? ''))
                    ? []
                    : $values,
            );
        }

        return new AttributeSchema($categoryId, $definitions);
    }

    public function publish(ListingDraft $draft): PublishedListing
    {
        $this->assertSellerBootstrapped($draft);

        // 1. The inventory item, keyed by SKU.
        $this->inventory->createOrReplaceInventoryItem($draft->sku, new Ebay\InventoryItem(
            availability: new Ebay\Availability(
                shipToLocationAvailability: new Ebay\ShipToLocationAvailability(quantity: $draft->quantity),
            ),
            condition: $this->conditionFor($draft->condition),
            product: new Ebay\Product(
                aspects: $this->aspectsFor($draft),
                description: $draft->description,
                imageUrls: array_map(static fn (ListingImage $i): string => $i->url, $draft->images),
                title: $draft->title,
            ),
        ));

        // 2. The offer.
        $offer = $this->inventory->createOffer(new Ebay\EbayOfferDetailsWithKeys(
            availableQuantity: $draft->quantity,
            categoryId: $draft->categoryId,
            format: ListingFormat::Auction === $draft->format ? 'AUCTION' : 'FIXED_PRICE',
            listingDescription: $draft->description,
            listingPolicies: new Ebay\ListingPolicies(
                fulfillmentPolicyId: $this->fulfillmentPolicyId,
                paymentPolicyId: $this->paymentPolicyId,
                returnPolicyId: $this->returnPolicyId,
            ),
            marketplaceId: $this->marketplaceId,
            merchantLocationKey: $this->merchantLocationKey,
            pricingSummary: new Ebay\PricingSummary(
                price: new Ebay\Amount(
                    currency: $draft->price->currency,
                    value: $draft->price->toDecimal(),
                ),
            ),
            sku: $draft->sku,
        ));

        if (null === $offer->offerId) {
            throw new ListingRejectedException('ebay', [], 'eBay created no offer id.');
        }

        // 3. Publish.
        $published = $this->inventory->publishOffer($offer->offerId);

        return new PublishedListing(
            provider: 'ebay',
            // The offer id, NOT the listing id: it is the handle every eBay write
            // operation accepts, including withdraw(). The listing id is the public
            // identity and is kept in raw.
            externalId: $offer->offerId,
            sku: $draft->sku,
            url: null !== $published->listingId
                ? sprintf('https://www.ebay.com/itm/%s', $published->listingId)
                : null,
            publishedAt: new \DateTimeImmutable(),
            raw: ['offerId' => $offer->offerId, 'listingId' => $published->listingId],
        );
    }

    public function withdraw(string $externalId): void
    {
        $this->inventory->withdrawOffer($externalId);
    }

    private function categoryTreeId(): string
    {
        return $this->categoryTreeId ??= (string) $this->taxonomy
            ->getDefaultCategoryTreeId($this->marketplaceId)
            ->categoryTreeId;
    }

    private function parseRelevancy(?string $relevancy): ?float
    {
        if (null === $relevancy || !preg_match('/^([\d.]+)\s*%?$/', trim($relevancy), $m)) {
            return null;
        }

        $value = (float) $m[1];

        return str_contains($relevancy, '%') ? $value / 100 : $value;
    }

    /** @return array<string, list<string>> */
    private function aspectsFor(ListingDraft $draft): array
    {
        $aspects = [];
        foreach ($draft->attributes as $name => $value) {
            // eBay wants every aspect value as an array of strings, even single ones.
            $aspects[$name] = array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                is_array($value) ? $value : [$value],
            ));
        }

        return $aspects;
    }

    private function conditionFor(ListingCondition $condition): string
    {
        return match ($condition) {
            ListingCondition::New => 'NEW',
            ListingCondition::NewOther => 'NEW_OTHER',
            ListingCondition::LikeNew => 'LIKE_NEW',
            ListingCondition::UsedExcellent => 'USED_EXCELLENT',
            ListingCondition::UsedVeryGood => 'USED_VERY_GOOD',
            ListingCondition::UsedGood => 'USED_GOOD',
            ListingCondition::UsedAcceptable => 'USED_ACCEPTABLE',
            ListingCondition::ForPartsOrNotWorking => 'FOR_PARTS_OR_NOT_WORKING',
        };
    }

    private function assertSellerBootstrapped(ListingDraft $draft): void
    {
        $missing = [];
        foreach ([
            'merchant_location_key' => $this->merchantLocationKey,
            'fulfillment_policy_id' => $this->fulfillmentPolicyId,
            'payment_policy_id' => $this->paymentPolicyId,
            'return_policy_id' => $this->returnPolicyId,
        ] as $option => $value) {
            if (null === $value || '' === $value) {
                $missing[] = $option;
            }
        }

        if ([] === $missing) {
            return;
        }

        // Fail here rather than at eBay, whose error for a missing policy names
        // neither the policy nor what to do about it.
        throw new ListingRejectedException('ebay', array_map(static fn (string $option): ListingViolation => new ListingViolation(ViolationCode::CategoryMissing, $option, sprintf('eBay cannot publish without "%s". Create the seller\'s business policies and merchant location first (Account API + createInventoryLocation), then supply the ids as connection options.', $option)), $missing), sprintf('eBay seller is not bootstrapped: missing %s.', implode(', ', $missing)));
    }
}
