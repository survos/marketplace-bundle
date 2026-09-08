<?php

declare(strict_types=1);

/**
 * The sibling packages this bundle adapts (survos/marketplace-contracts,
 * survos/ebay-php, survos/mercadolibre-php) are not published yet, so composer
 * cannot resolve them from inside this directory.
 *
 * Rather than adding path repositories to composer.json -- which would be wrong in
 * the split package, where these are ordinary versioned dependencies -- the test
 * suite autoloads them straight from the monorepo. Third-party classes come from
 * the monorepo's own vendor.
 *
 * Delete this once the packages are on Packagist and composer can do it properly.
 */

$mono = dirname(__DIR__, 3);

require $mono . '/vendor/autoload.php';

$map = [
    'Survos\\MarketplaceBundle\\' => __DIR__ . '/../src/',
    'Survos\\MarketplaceContracts\\' => $mono . '/lib/marketplace-contracts/src/',
    'Survos\\Ebay\\' => $mono . '/lib/ebay-php/src/',
    'Survos\\MercadoLibre\\' => $mono . '/lib/mercadolibre-php/src/',
    'Survos\\MarketplaceBundle\\Tests\\' => __DIR__ . '/',
];

spl_autoload_register(static function (string $class) use ($map): void {
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
