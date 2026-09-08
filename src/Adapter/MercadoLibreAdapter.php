<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Model\AttributeDefinition;
use Survos\MarketplaceContracts\Model\AttributeSchema;
use Survos\MarketplaceContracts\Model\AttributeValueType;
use Survos\MarketplaceContracts\Model\CategorySuggestion;
use Survos\MarketplaceContracts\Model\ListingCondition;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingFormat;
use Survos\MarketplaceContracts\Model\ListingImage;
use Survos\MarketplaceContracts\Model\MarketplaceLimits;
use Survos\MarketplaceContracts\Model\ProviderCapability;
use Survos\MarketplaceContracts\Model\PublishedListing;
use Survos\MercadoLibre\Api\CategoriesApi;
use Survos\MercadoLibre\Api\ItemsApi;
use Survos\MercadoLibre\MercadoLibreSite;
use Survos\MercadoLibre\Model\BuyingMode;
use Survos\MercadoLibre\Model\CategoryAttribute;
use Survos\MercadoLibre\Model\ItemAttribute;
use Survos\MercadoLibre\Model\ItemCondition;
use Survos\MercadoLibre\Model\ListingType;
use Survos\MercadoLibre\Model\NewItem;
use Survos\MercadoLibre\Model\Picture;

/**
 * Mercado Libre, behind the provider-neutral interface.
 *
 * Publishing is one call and there is nothing to bootstrap -- no policies, no
 * locations, no opt-in. The interesting work here is the other direction:
 * Mercado Libre gives back MORE than the neutral model can express, and this
 * adapter has to decide what to keep.
 *
 * Specifically, attributes come with value IDs. Sending `value_id` rather than
 * `value_name` is materially better -- a free-text name on a closed-set attribute
 * publishes and then silently fails catalog matching later. But ListingDraft
 * carries plain strings, because eBay has no equivalent concept. So the adapter
 * resolves names back to ids at publish time from the category schema it already
 * had to fetch. That resolution is the reason this adapter caches the schema.
 */
final class MercadoLibreAdapter implements MarketplaceAdapterInterface
{
    /** @var array<string, list<CategoryAttribute>> */
    private array $attributeCache = [];

    public function __construct(
        private readonly ItemsApi $items,
        private readonly CategoriesApi $categories,
        private readonly MercadoLibreSite $mlSite = MercadoLibreSite::Mexico,
        private readonly ListingType $listingType = ListingType::Classic,
    ) {
    }

    public function provider(): string
    {
        return 'mercadolibre';
    }

    public function site(): string
    {
        return $this->mlSite->value;
    }

    public function capabilities(): array
    {
        return [
            // No Sandbox: Mercado Libre has none. Test users on production instead.
            ProviderCapability::CategorySuggestion,
            ProviderCapability::AttributeSchema,
            ProviderCapability::Withdraw,
            ProviderCapability::RemoteImageIngest,
        ];
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function limits(): MarketplaceLimits
    {
        // 60, not eBay's 80. A title generated once and offered to both providers
        // must respect the tighter cap or it is not portable.
        return new MarketplaceLimits(titleMaxLength: 60, maxImages: 12);
    }

    public function suggestCategories(string $title, int $limit = 5): array
    {
        $suggestions = [];

        foreach ($this->categories->predict($title, $limit) as $prediction) {
            $suggestions[] = new CategorySuggestion(
                categoryId: $prediction->categoryId,
                name: $prediction->categoryName,
                // Mercado Libre returns the domain rather than an ancestry path;
                // it is the closest thing to a breadcrumb available here.
                path: $prediction->domainName !== null
                    ? [$prediction->domainName, $prediction->categoryName]
                    : [$prediction->categoryName],
                // No confidence score is returned, and inventing one would be worse
                // than admitting there is none.
                confidence: null,
            );
        }

        return $suggestions;
    }

    public function attributeSchema(string $categoryId): AttributeSchema
    {
        $definitions = [];

        foreach ($this->writableAttributes($categoryId) as $attribute) {
            $definitions[] = new AttributeDefinition(
                name: $attribute->id,
                required: $attribute->required,
                valueType: match ($attribute->valueType) {
                    'number', 'number_unit' => AttributeValueType::Number,
                    'boolean' => AttributeValueType::Boolean,
                    default => AttributeValueType::String,
                },
                // Only value_type 'list' is a genuine closed set. A 'string'
                // attribute's values are SAMPLES -- ORIGIN offers "España, Francia"
                // in a Mexican postcard category -- so promoting them to an enum
                // would force a model to mislabel a Chiapas postcard as Spanish.
                allowedValues: 'list' === $attribute->valueType
                    ? array_map(static fn ($v): string => $v->name, $attribute->allowedValues)
                    : [],
                maxValues: $attribute->multivalued ? null : 1,
                // The localized label. The neutral model keys on the stable id
                // (BRAND), so the human name would otherwise be lost.
                hint: $attribute->name,
                examples: 'list' === $attribute->valueType
                    ? []
                    : array_map(static fn ($v): string => $v->name, $attribute->allowedValues),
            );
        }

        return new AttributeSchema($categoryId, $definitions);
    }

    public function publish(ListingDraft $draft): PublishedListing
    {
        $item = new NewItem(
            title: $draft->title,
            categoryId: (string) $draft->categoryId,
            price: $draft->price->toDecimal(),
            currencyId: $draft->price->currency,
            availableQuantity: $draft->quantity,
            condition: $this->conditionFor($draft->condition),
            listingType: $this->listingType,
            buyingMode: $draft->format === ListingFormat::Auction ? BuyingMode::Auction : BuyingMode::BuyItNow,
            pictures: array_map(
                static fn (ListingImage $i): Picture => new Picture($i->url),
                $draft->images,
            ),
            attributes: $this->attributesFor($draft),
            description: $draft->description,
            // Mercado Libre has no SKU field on a plain listing; this is the closest.
            sellerCustomField: $draft->sku,
        );

        $published = $this->items->publish($item);

        return new PublishedListing(
            provider: 'mercadolibre',
            externalId: $published->id,
            sku: $draft->sku,
            url: $published->permalink,
            publishedAt: $published->createdAt ?? new \DateTimeImmutable(),
            raw: $published->raw,
        );
    }

    public function withdraw(string $externalId): void
    {
        // `closed` is terminal on Mercado Libre -- a closed listing cannot be
        // reopened, only relisted as a new item. That matches withdraw()'s meaning
        // better than `paused` does.
        $this->items->close($externalId);
    }

    /** @return list<CategoryAttribute> */
    private function writableAttributes(string $categoryId): array
    {
        return $this->attributeCache[$categoryId] ??= $this->categories->writableAttributes($categoryId);
    }

    /**
     * Map the draft's plain string attributes onto Mercado Libre attributes,
     * upgrading to `value_id` wherever the category declares a closed set.
     *
     * @return list<ItemAttribute>
     */
    private function attributesFor(ListingDraft $draft): array
    {
        $byId = [];
        if ($draft->categoryId !== null) {
            foreach ($this->writableAttributes($draft->categoryId) as $attribute) {
                $byId[$attribute->id] = $attribute;
            }
        }

        $attributes = [];
        foreach ($draft->attributes as $name => $value) {
            // Multi-valued attributes are not expressible as a single ItemAttribute;
            // Mercado Libre wants repeated entries, so emit one per value.
            foreach (is_array($value) ? $value : [$value] as $single) {
                $single = (string) $single;
                $definition = $byId[$name] ?? null;
                $valueId = $definition?->valueIdFor($single);

                $attributes[] = new ItemAttribute(
                    id: $name,
                    valueName: $valueId === null ? $single : null,
                    valueId: $valueId,
                );
            }
        }

        return $attributes;
    }

    private function conditionFor(ListingCondition $condition): ItemCondition
    {
        // Mercado Libre has three values to eBay's eight. Coarsening is lossy and
        // deliberately one-way: "Used - very good" and "For parts" both become
        // `used`, and the distinction has to survive in the description instead.
        return $condition->isNew() ? ItemCondition::NewItem : ItemCondition::Used;
    }
}
