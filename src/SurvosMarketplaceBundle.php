<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Ebay\Auth\EbayCredentials;
use Survos\Ebay\EbayEnvironment;
use Survos\MarketplaceBundle\Adapter\EbayAdapterFactory;
use Survos\MarketplaceBundle\Adapter\MercadoLibreAdapterFactory;
use Survos\MarketplaceBundle\Service\MarketplaceService;
use Survos\MarketplaceBundle\Token\EbayTokenProviderFactory;
use Survos\MarketplaceBundle\Token\FileTokenStore;
use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Registry\MarketplaceRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Publish listings to eBay and Mercado Libre through one interface.
 *
 * The adapters are registered here; the clients they wrap (survos/ebay-php,
 * survos/mercadolibre-php) are optional, so an app that only sells on one
 * marketplace does not have to install the other.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosMarketplaceBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('token_dir')
                    ->info('Where FileTokenStore keeps OAuth tokens. One JSON file per connection; keep it out of the webroot and out of git.')
                    ->defaultValue('%kernel.project_dir%/var/marketplace-tokens')
                ->end()
                ->arrayNode('ebay')
                    ->info('eBay application keyset. Sandbox and production keysets are separate and not interchangeable.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('client_id')->defaultNull()->end()
                        ->scalarNode('client_secret')->defaultNull()->end()
                        ->scalarNode('ru_name')
                            ->info("eBay's redirect-URI ALIAS, not a URL. Passing the URL fails as an unhelpful invalid_request.")
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('connections')
                    ->info('One entry per seller account. The key is the connection name used to look up tokens.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('driver')->values(['ebay', 'mercadolibre'])->isRequired()->end()
                            ->scalarNode('site')
                                ->info('Provider marketplace id: EBAY_US, or an ML site such as MLM (Mexico).')
                                ->isRequired()
                            ->end()
                            ->booleanNode('sandbox')
                                ->info('eBay only. Mercado Libre has no sandbox and the adapter refuses this rather than publishing to production.')
                                ->defaultFalse()
                            ->end()
                            ->variableNode('options')
                                ->info('Driver options. eBay needs merchant_location_key plus the three business policy ids.')
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $builder->setParameter('survos_marketplace.connections', $config['connections'] ?? []);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(MarketplaceRegistry::class)
            ->args([tagged_iterator('survos_marketplace.adapter_factory')]);

        $services->set(MarketplaceService::class)
            ->args([service(MarketplaceRegistry::class), '%survos_marketplace.connections%']);

        $services->set(FileTokenStore::class)->args([$config['token_dir']]);
        $services->alias(TokenStoreInterface::class, FileTokenStore::class);

        // Each adapter is registered only when its client is actually installed, so
        // an app that sells on one marketplace need not require the other.
        if (class_exists(EbayCredentials::class)) {
            $services->set(EbayTokenProviderFactory::class)
                ->args([
                    service('http_client'),
                    service(TokenStoreInterface::class),
                    // (string) rather than ?? '': an env placeholder is a non-null
                    // string at build time and resolves to null at runtime, so ??
                    // never fires and EbayCredentials gets null for a string arg.
                    inline_service(EbayCredentials::class)->args([
                        (string) ($config['ebay']['client_id'] ?? ''),
                        (string) ($config['ebay']['client_secret'] ?? ''),
                        $config['ebay']['ru_name'],
                    ]),
                    EbayEnvironment::Sandbox,
                ]);

            $services->set(EbayAdapterFactory::class)
                ->args([
                    service('http_client'),
                    // The factory takes a callable, not a provider: one process may
                    // talk to several sellers, and each needs its own token.
                    [service(EbayTokenProviderFactory::class), 'forConnection'],
                ])
                ->tag('survos_marketplace.adapter_factory');
        }

        if (class_exists(\Survos\MercadoLibre\MercadoLibreSite::class)) {
            $services->set(MercadoLibreAdapterFactory::class)
                // No token provider yet: category discovery and attribute lookup are
                // public, and that is the half of the interface worth having before a
                // seller has connected anything.
                ->args([service('http_client'), null])
                ->tag('survos_marketplace.adapter_factory');
        }
    }
}
