<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\MarketplaceContracts\Contract\AdapterFactoryInterface;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\MarketplaceConfigurationException;
use Survos\MarketplaceContracts\Exception\UnsupportedMarketplaceOperation;
use Survos\MarketplaceContracts\Model\ConnectionConfiguration;
use Survos\MercadoLibre\Api\CategoriesApi;
use Survos\MercadoLibre\Api\ItemsApi;
use Survos\MercadoLibre\Auth\AccessTokenProviderInterface;
use Survos\MercadoLibre\Http\MercadoLibreTransport;
use Survos\MercadoLibre\MercadoLibreSite;
use Survos\MercadoLibre\Model\ListingType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MercadoLibreAdapterFactory implements AdapterFactoryInterface
{
    /** @param callable(string): AccessTokenProviderInterface $tokenProviderFactory */
    public function __construct(
        private HttpClientInterface $httpClient,
        private mixed $tokenProviderFactory,
    ) {
    }

    public function driver(): string
    {
        return 'mercadolibre';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'mercadolibre' || $driver === 'meli';
    }

    public function create(ConnectionConfiguration $connection): MarketplaceAdapterInterface
    {
        if ($connection->sandbox) {
            // Silently publishing to production because someone asked for a sandbox
            // that does not exist is the worst possible outcome here.
            throw UnsupportedMarketplaceOperation::noSandbox('mercadolibre');
        }

        $site = MercadoLibreSite::tryFrom($connection->site)
            ?? throw new MarketplaceConfigurationException(sprintf(
                'Unknown Mercado Libre site "%s". Expected one of: %s.',
                $connection->site,
                implode(', ', array_column(MercadoLibreSite::cases(), 'value')),
            ));

        $transport = new MercadoLibreTransport(
            $this->httpClient,
            ($this->tokenProviderFactory)($connection->name),
        );

        return new MercadoLibreAdapter(
            items: new ItemsApi($transport),
            categories: new CategoriesApi($transport, $site),
            mlSite: $site,
            listingType: ListingType::tryFrom((string) $connection->option('listing_type', ListingType::Classic->value))
                ?? ListingType::Classic,
        );
    }
}
