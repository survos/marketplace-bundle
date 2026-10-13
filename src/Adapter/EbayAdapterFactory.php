<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\Ebay\Auth\AccessTokenProviderInterface;
use Survos\Ebay\EbayEnvironment;
use Survos\Ebay\Generated\Commerce\Taxonomy\TaxonomyApi;
use Survos\Ebay\Generated\Sell\Inventory\InventoryApi;
use Survos\Ebay\Http\EbayTransport;
use Survos\MarketplaceContracts\Contract\AdapterFactoryInterface;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Model\ConnectionConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EbayAdapterFactory implements AdapterFactoryInterface
{
    /**
     * @param callable(string): AccessTokenProviderInterface $tokenProviderFactory
     *                                                                             resolves a connection name to that seller's token provider
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private mixed $tokenProviderFactory,
    ) {
    }

    public function driver(): string
    {
        return 'ebay';
    }

    public function supports(string $driver): bool
    {
        return 'ebay' === $driver;
    }

    public function create(ConnectionConfiguration $connection): MarketplaceAdapterInterface
    {
        $environment = $connection->sandbox ? EbayEnvironment::Sandbox : EbayEnvironment::Production;

        $transport = new EbayTransport(
            $this->httpClient,
            ($this->tokenProviderFactory)($connection->name),
            $environment,
            marketplaceId: $connection->site,
            contentLanguage: (string) $connection->option('content_language', 'en-US'),
        );

        return new EbayAdapter(
            inventory: new InventoryApi($transport),
            taxonomy: new TaxonomyApi($transport),
            marketplaceId: $connection->site,
            merchantLocationKey: $connection->option('merchant_location_key'),
            fulfillmentPolicyId: $connection->option('fulfillment_policy_id'),
            paymentPolicyId: $connection->option('payment_policy_id'),
            returnPolicyId: $connection->option('return_policy_id'),
        );
    }
}
