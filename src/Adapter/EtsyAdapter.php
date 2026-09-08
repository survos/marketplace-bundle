<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\Etsy\Generated\Model as Etsy;
use Survos\Etsy\Generated\SellerTaxonomyApi;
use Survos\Etsy\Http\EtsyTransportInterface;
use Survos\Etsy\Generated\ShopListingApi;
use Survos\Etsy\Generated\ShopProcessingProfilesApi;
use Survos\Etsy\Generated\ShopShippingProfileApi;
use Survos\MarketplaceContracts\Contract\DraftPublisherInterface;
use Survos\MarketplaceContracts\Contract\ListingRemoverInterface;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\ListingRejectedException;
use Survos\MarketplaceContracts\Model\AttributeDefinition;
use Survos\MarketplaceContracts\Model\AttributeSchema;
use Survos\MarketplaceContracts\Model\AttributeValueType;
use Survos\MarketplaceContracts\Model\CategorySuggestion;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingImage;
use Survos\MarketplaceContracts\Model\MarketplaceLimits;
use Survos\MarketplaceContracts\Model\ProviderCapability;
use Survos\MarketplaceContracts\Model\PublishedListing;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Etsy, behind the provider-neutral interface.
 *
 * Two things about Etsy shape this adapter more than anything else.
 *
 * PUBLISHING CREATES A DRAFT, ALWAYS. Etsy removed its sandbox, so every call
 * touches a real shop with real listing fees. Their own API testing policy asks
 * for draft listings, and `createDraftListing` is the only creation endpoint --
 * the seller reviews and publishes from Etsy's own app. That fits how a seller
 * actually wants to work anyway, so it is not treated as a limitation.
 *
 * THERE IS NO CATEGORY SUGGESTION ENDPOINT. eBay has getCategorySuggestions and
 * Mercado Libre has domain_discovery; Etsy has only the full seller taxonomy tree.
 * So suggestCategories() fetches the tree once and matches locally. That is worse
 * than either provider's server-side answer, and the honest thing is to score it
 * transparently rather than pretend it is the same operation.
 */
final class EtsyAdapter implements MarketplaceAdapterInterface, DraftPublisherInterface, ListingRemoverInterface
{
    /** @var list<array{id: int, name: string, path: list<string>}>|null */
    private ?array $taxonomy = null;

    public function __construct(
        private readonly ShopListingApi $listings,
        private readonly SellerTaxonomyApi $taxonomyApi,
        private readonly ShopShippingProfileApi $shippingProfiles,
        private readonly ShopProcessingProfilesApi $processingProfiles,
        private readonly EtsyTransportInterface $transport,
        private readonly HttpClientInterface $httpClient,
        private readonly int $shopId,
        /**
         * Required for PHYSICAL listings, though Etsy's contract does not say so:
         * shipping_profile_id is absent from createDraftListing's `required` list,
         * and the API still rejects the call with
         * "A shipping_profile_id is required for physical listings."
         *
         * Null means "use the shop's first profile", which is right for a seller
         * with one and wrong for a seller with several -- hence configurable.
         */
        private readonly ?int $shippingProfileId = null,
        /**
         * Also required for physical listings, and also absent from the
         * contract's `required` list. Etsy calls it a processing profile in the
         * UI and a readiness state in the API. Discovered the same way the
         * shipping profile was: by the API refusing the call.
         */
        private readonly ?int $readinessStateId = null,
        /** Etsy demands who_made/when_made on every listing; these are the defaults. */
        private readonly string $whoMade = 'someone_else',
        private readonly string $whenMade = 'before_2007',
    ) {
    }

    public function provider(): string
    {
        return 'etsy';
    }

    public function site(): string
    {
        return 'ETSY';
    }

    public function capabilities(): array
    {
        return [
            // No Sandbox: Etsy removed it. Drafts are the safety margin instead.
            ProviderCapability::CategorySuggestion,
            ProviderCapability::AttributeSchema,
            ProviderCapability::Withdraw,
            // NOT RemoteImageIngest. eBay and Mercado Libre fetch an image from a
            // URL; Etsy's endpoint takes the actual bytes, so this adapter has to
            // read each picture and upload it.
        ];
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function limits(): MarketplaceLimits
    {
        // 140 characters, far more generous than eBay's 80 or Mercado Libre's 60 --
        // so a title written for the tightest marketplace always fits here.
        return new MarketplaceLimits(titleMaxLength: 140, maxImages: 10);
    }

    public function suggestCategories(string $title, int $limit = 5): array
    {
        $words = array_values(array_filter(
            preg_split('/\W+/u', mb_strtolower($title)) ?: [],
            static fn (string $w): bool => mb_strlen($w) > 2,
        ));

        if ($words === []) {
            return [];
        }

        $scored = [];
        foreach ($this->taxonomy() as $node) {
            $haystack = mb_strtolower($node['name'] . ' ' . implode(' ', $node['path']));
            $hits = 0;
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    ++$hits;
                }
            }

            if ($hits > 0) {
                $scored[] = [$hits / count($words), $node];
            }
        }

        // Deeper nodes first at equal score: "Postcards" beats "Paper & Party Supplies".
        usort($scored, static fn (array $a, array $b): int
            => [$b[0], count($b[1]['path'])] <=> [$a[0], count($a[1]['path'])]);

        $suggestions = [];
        foreach (array_slice($scored, 0, $limit) as [$score, $node]) {
            $suggestions[] = new CategorySuggestion(
                categoryId: (string) $node['id'],
                name: $node['name'],
                path: $node['path'],
                // A local word-overlap score, NOT a provider confidence. Reported
                // anyway so a caller can see how weak the match is.
                confidence: round($score, 2),
            );
        }

        return $suggestions;
    }

    public function attributeSchema(string $categoryId): AttributeSchema
    {
        $properties = $this->taxonomyApi->getPropertiesByTaxonomyId((int) $categoryId);

        $definitions = [];
        foreach ($properties->results ?? [] as $property) {
            if (!$property instanceof Etsy\TaxonomyNodeProperty || null === $property->name) {
                continue;
            }

            $values = [];
            foreach ($property->possible_values ?? [] as $value) {
                if ($value instanceof Etsy\TaxonomyPropertyValue && null !== $value->name) {
                    $values[] = $value->name;
                }
            }

            $definitions[] = new AttributeDefinition(
                name: $property->name,
                required: (bool) ($property->is_required ?? false),
                valueType: AttributeValueType::String,
                // Etsy's scales/values are a menu only when the property forbids
                // free text; otherwise they are suggestions, same trap as the others.
                allowedValues: false === ($property->supports_variations ?? false) && $values !== [] ? $values : [],
                maxValues: ($property->supports_variations ?? false) ? null : 1,
                hint: $property->display_name,
                examples: $values,
            );
        }

        return new AttributeSchema($categoryId, $definitions);
    }

    /** A single-valued draft attribute, or null when absent or multi-valued. */
    private function attr(ListingDraft $draft, string $name): ?string
    {
        $value = $draft->attributes[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Etsy's tag and material rules are strict and enforced at write time: at most
     * 13 entries, 20 characters each, and only letters, numbers, spaces and a few
     * punctuation marks. A single bad entry rejects the whole listing, so anything
     * that cannot be cleaned is dropped rather than sent.
     *
     * @return list<string>
     */
    private function tagList(ListingDraft $draft, string $name): array
    {
        $raw = $draft->attributes[$name] ?? [];
        $raw = is_string($raw) ? [$raw] : $raw;

        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            // Accents are transliterated rather than stripped, so "Curaçao" stays
            // "Curacao" instead of collapsing to "Curaao".
            $tag = @iconv('UTF-8', 'ASCII//TRANSLIT', $tag) ?: $tag;
            $tag = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 \-\x27]/', ' ', $tag)) ?? '');

            if ('' === $tag || mb_strlen($tag) > 20) {
                continue;
            }

            $key = mb_strtolower($tag);
            $clean[$key] ??= $tag;
        }

        return array_slice(array_values($clean), 0, 13);
    }

    public function publish(ListingDraft $draft): PublishedListing
    {
        if (null === $draft->categoryId) {
            throw new ListingRejectedException('etsy', [], 'Etsy needs a taxonomy_id; resolve a category first.');
        }

        $listing = $this->listings->createDraftListing($this->shopId, new Etsy\CreateDraftListingRequest(
            quantity: $draft->quantity,
            title: $draft->title,
            description: $draft->description,
            price: (float) $draft->price->toDecimal(),
            who_made: $this->attr($draft, 'who_made') ?? $this->whoMade,
            when_made: $this->attr($draft, 'when_made') ?? $this->whenMade,
            taxonomy_id: (int) $draft->categoryId,
            shipping_profile_id: $this->resolveShippingProfileId(),
            readiness_state_id: $this->resolveReadinessStateId(),
            materials: $this->tagList($draft, 'materials') ?: null,
            tags: $this->tagList($draft, 'tags') ?: null,
        ));

        if (null === $listing->listing_id) {
            throw new ListingRejectedException('etsy', [], 'Etsy returned no listing id.');
        }

        // Images are a second call each, and Etsy wants BYTES -- there is no
        // "fetch this URL" option and no way to attach them on create. So each one
        // is downloaded and re-uploaded.
        //
        // A failure here does NOT undo the listing: it is a draft, the seller can
        // see which pictures are missing, and destroying their draft over one
        // unreachable photo would be worse than an incomplete one.
        $uploaded = 0;
        $imageErrors = [];
        foreach (array_values($draft->images) as $index => $image) {
            if (!$image instanceof ListingImage) {
                continue;
            }

            try {
                $bytes = $this->httpClient->request('GET', $image->url)->getContent();
                $this->transport->upload(
                    sprintf('/v3/application/shops/%d/listings/%d/images', $this->shopId, $listing->listing_id),
                    'image',
                    $bytes,
                    sprintf('%s-%d.jpg', $draft->sku, $index + 1),
                    ['rank' => $index + 1],
                );
                ++$uploaded;
            } catch (\Throwable $e) {
                $imageErrors[] = $image->url . ': ' . $e->getMessage();
            }
        }

        return new PublishedListing(
            provider: 'etsy',
            externalId: (string) $listing->listing_id,
            sku: $draft->sku,
            url: $listing->url,
            publishedAt: new \DateTimeImmutable(),
            // state is 'draft': live only once the seller publishes it in Etsy's app.
            raw: [
                'listing_id' => $listing->listing_id,
                'state' => $listing->state,
                'imagesUploaded' => $uploaded,
                'imageErrors' => $imageErrors,
            ],
        );
    }

    /**
     * Take the listing down, reversibly.
     *
     * This used to call deleteListing, which made Etsy's withdraw() far more
     * destructive than the other adapters': eBay ends an offer but keeps it,
     * Mercado Libre closes an item but the record survives. Deactivating matches
     * them, and matches what a seller means by "I sold it elsewhere" — the
     * listing, its views and its favourites all survive, and it can be relisted.
     *
     * Permanent removal is {@see delete()}, which says so.
     */
    public function withdraw(string $externalId): void
    {
        $this->listings->updateListing(
            $this->shopId,
            (int) $externalId,
            new Etsy\UpdateListingRequest(state: 'inactive'),
        );
    }

    public function delete(string $externalId): void
    {
        $this->listings->deleteListing((int) $externalId);
    }

    public function createsDrafts(): bool
    {
        // Not a configuration choice: createDraftListing is Etsy's only creation
        // endpoint, so everything this adapter makes starts invisible.
        return true;
    }

    public function activate(string $externalId): PublishedListing
    {
        // PATCH with state=active is what turns a draft into a live listing. This
        // is the moment Etsy charges the $0.20 listing fee, which is exactly why
        // it is a separate, deliberate call rather than part of publish().
        $listing = $this->listings->updateListing(
            $this->shopId,
            (int) $externalId,
            new Etsy\UpdateListingRequest(state: 'active'),
        );

        return new PublishedListing(
            provider: 'etsy',
            externalId: $externalId,
            // Etsy's ShopListing carries no sku field; the caller already knows
            // which of its own items this was, so inventing one here would be worse
            // than leaving it empty.
            sku: '',
            url: $listing->url,
            publishedAt: new \DateTimeImmutable(),
            raw: ['listing_id' => $listing->listing_id, 'state' => $listing->state],
        );
    }

    /**
     * The shipping profile to attach.
     *
     * Falls back to the shop's first profile when none is configured, and fails
     * with the available ids when the shop has none -- which is a real state: a
     * seller who only lists digital items has no shipping profile at all, and
     * Etsy's own error does not say what to do about it.
     */
    private function resolveShippingProfileId(): int
    {
        if (null !== $this->shippingProfileId) {
            return $this->shippingProfileId;
        }

        $profiles = $this->shippingProfiles->getShopShippingProfiles($this->shopId)->results ?? [];

        foreach ($profiles as $profile) {
            if ($profile instanceof Etsy\ShopShippingProfile && null !== $profile->shipping_profile_id) {
                return $profile->shipping_profile_id;
            }
        }

        throw new ListingRejectedException('etsy', [], sprintf(
            'Etsy requires a shipping_profile_id for physical listings and shop %d has none. '
            . 'Create one in Shop Manager > Settings > Shipping settings, then set '
            . 'shipping_profile_id in the connection options.',
            $this->shopId,
        ));
    }

    /**
     * The processing profile ("readiness state") to attach.
     *
     * Same shape as the shipping profile, and required for the same reason: the
     * contract does not mark it required, the API refuses the call without it.
     */
    private function resolveReadinessStateId(): int
    {
        if (null !== $this->readinessStateId) {
            return $this->readinessStateId;
        }

        $profiles = $this->processingProfiles->getShopReadinessStateDefinitions($this->shopId)->results ?? [];

        foreach ($profiles as $profile) {
            $id = $profile->readiness_state_id ?? $profile->processing_profile_id ?? null;
            if (null !== $id) {
                return (int) $id;
            }
        }

        throw new ListingRejectedException('etsy', [], sprintf(
            'Etsy requires a readiness_state_id (processing profile) for physical listings and '
            . 'shop %d has none. Create one in Shop Manager > Settings > Shipping settings, then '
            . 'set readiness_state_id in the connection options.',
            $this->shopId,
        ));
    }

    /**
     * The seller taxonomy, flattened to leaf-ish nodes with their ancestry.
     *
     * Fetched once per adapter: it is a large, slow, and almost entirely static
     * document, and re-fetching it per suggestion would make category resolution
     * the most expensive part of listing an item.
     *
     * @return list<array{id: int, name: string, path: list<string>}>
     */
    private function taxonomy(): array
    {
        if (null !== $this->taxonomy) {
            return $this->taxonomy;
        }

        $flat = [];
        $walk = static function (array $nodes, array $trail) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Etsy\SellerTaxonomyNode || null === $node->id || null === $node->name) {
                    continue;
                }

                $path = [...$trail, $node->name];
                $flat[] = ['id' => $node->id, 'name' => $node->name, 'path' => $path];

                if (is_array($node->children) && $node->children !== []) {
                    $walk($node->children, $path);
                }
            }
        };

        $walk($this->taxonomyApi->getSellerTaxonomyNodes()->results ?? [], []);

        return $this->taxonomy = $flat;
    }
}
