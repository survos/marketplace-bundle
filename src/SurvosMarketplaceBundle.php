<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\MarketplaceBundle\Service\MarketplaceService;
use Survos\MarketplaceContracts\Registry\MarketplaceRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
    }
}
