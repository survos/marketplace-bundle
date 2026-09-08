# survos/marketplace-bundle

Publish listings to eBay and Mercado Libre through one provider-neutral interface.

```bash
composer require survos/marketplace-bundle
```

The clients are optional — install only the marketplace you sell on:

```bash
composer require survos/ebay-php          # EbayAdapter
composer require survos/mercadolibre-php  # MercadoLibreAdapter
```

## Configuration

```yaml
survos_marketplace:
    connections:
        dave_postcards:
            driver: ebay
            site: EBAY_US
            sandbox: true
            options:
                merchant_location_key: SAN_CRISTOBAL
                fulfillment_policy_id: '%env(EBAY_FULFILLMENT_POLICY)%'
                payment_policy_id:     '%env(EBAY_PAYMENT_POLICY)%'
                return_policy_id:      '%env(EBAY_RETURN_POLICY)%'
        chijal:
            driver: mercadolibre
            site: MLM          # Mexico
```

```
bin/console marketplace:connections
bin/console marketplace:categories dave_postcards "Lot of 5 Vintage Animal Postcards"
bin/console marketplace:attributes chijal MLM429001 --json
```

## What the adapters normalize

The two providers encode the same ideas incompatibly. This is the whole job:

| | eBay | Mercado Libre |
|---|---|---|
| publish | 3 calls: inventory item → offer → publish | 1 call: `POST /items` |
| prerequisites | merchant location + 3 business policies | none |
| title cap | 80 | **60** |
| requiredness | `aspectConstraint.aspectRequired` | `tags.required` |
| allowed values | `aspectValues[].localizedValue` | `values[]` with **ids** |
| cardinality | `itemToAspectCardinality: MULTI` | `tags.multivalued` |
| unsettable attributes | — | `read_only` / `hidden` (37 of 56 on MLM429001) |
| confidence | relevancy, `"85%"` | not reported |
| conditions | 8 values | 3 |
| sandbox | yes | **none** |

Both collapse onto `AttributeSchema`, which is what makes the
category → required-attributes → generate → validate loop portable.

## Four things worth knowing

**The tighter title cap wins.** A title generated once and offered to both
providers must fit 60, not 80. Read `limits()` before generating, not after —
truncating an 80-character title to 60 ends it mid-word.

**eBay's `externalId` is the offer id, not the listing id.** It is the handle every
eBay write accepts, including `withdraw()`. The public listing id is in `raw` and in
`url`. On Mercado Libre both are the same item id.

**Mercado Libre attributes get upgraded to value IDs.** `ListingDraft` carries plain
strings because eBay has no equivalent, so `MercadoLibreAdapter` resolves names back
to ids from the category schema at publish time. A free-text name on a closed-set
attribute publishes and *then* fails catalog matching, which is worse than a
rejection.

**`sandbox: true` on a Mercado Libre connection throws.** It has no sandbox, and
silently publishing to production because someone asked for one that does not exist
is the worst available outcome. Use test users instead — see `survos/mercadolibre-php`.

## Condition mapping is lossy in one direction

eBay has eight conditions, Mercado Libre three. `UsedVeryGood` and
`ForPartsOrNotWorking` both become `used`; the distinction has to survive in the
description. The neutral enum keeps eBay's granularity because coarsening is
lossless in the direction adapters actually need.

## Tests

```bash
vendor/bin/phpunit -c bu/marketplace-bundle/phpunit.xml.dist   # from the monorepo root
```

`tests/bootstrap.php` autoloads the sibling packages from the monorepo, since they
are not on Packagist yet. Delete it once they are.
