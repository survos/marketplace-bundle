<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Adapter;

use Survos\Etsy\Auth\EtsyCredentials;
use Survos\Etsy\Generated\SellerTaxonomyApi;
use Survos\Etsy\Generated\ShopListingApi;
use Survos\Etsy\Http\EtsyTransport;
use Survos\MarketplaceBundle\Token\EtsyTokenProviderFactory;
use Survos\MarketplaceContracts\Contract\AdapterFactoryInterface;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\MarketplaceConfigurationException;
use Survos\MarketplaceContracts\Model\ConnectionConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Etsy credentials live on the CONNECTION, not on the bundle.
 *
 * This is the one adapter where that is true, and it is Etsy's doing. eBay and
 * Mercado Libre issue one application that many sellers authorize, so their keys
 * are app-level configuration. Etsy's instantly-approved "Seller App" tier is
 * scoped to the shop that registered it -- serving other people's shops needs
 * Commercial Access and a manual review. So in practice each seller registers
 * their own app and hands over a keystring, and the keystring belongs beside that
 * seller's connection rather than in a shared block.
 */
final readonly class EtsyAdapterFactory implements AdapterFactoryInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EtsyTokenProviderFactory $tokens,
    ) {
    }

    public function driver(): string
    {
        return 'etsy';
    }

    public function supports(string $driver): bool
    {
        return 'etsy' === $driver;
    }

    public function create(ConnectionConfiguration $connection): MarketplaceAdapterInterface
    {
        if ($connection->sandbox) {
            // Etsy removed its sandbox. Saying so beats letting someone believe a
            // test listing went somewhere harmless.
            throw new MarketplaceConfigurationException(
                'Etsy has no sandbox — it was removed. Test against a real shop with Developer Mode '
                . 'enabled and draft listings, per Etsy\'s API testing policy.',
            );
        }

        $credentials = new EtsyCredentials(
            keystring: (string) $connection->requiredOption('keystring'),
            sharedSecret: $connection->option('shared_secret'),
            redirectUri: $connection->option('redirect_uri'),
        );

        $transport = new EtsyTransport(
            $this->httpClient,
            $credentials,
            $this->tokens->forConnection($connection->name, $credentials),
        );

        return new EtsyAdapter(
            listings: new ShopListingApi($transport),
            taxonomyApi: new SellerTaxonomyApi($transport),
            transport: $transport,
            httpClient: $this->httpClient,
            shopId: (int) $connection->requiredOption('shop_id'),
            whoMade: (string) $connection->option('who_made', 'someone_else'),
            whenMade: (string) $connection->option('when_made', 'before_2007'),
        );
    }
}
