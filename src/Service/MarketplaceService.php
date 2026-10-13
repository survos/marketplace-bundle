<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Service;

use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\MarketplaceConfigurationException;
use Survos\MarketplaceContracts\Model\AttributeSchema;
use Survos\MarketplaceContracts\Model\ConnectionConfiguration;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingViolation;
use Survos\MarketplaceContracts\Registry\MarketplaceRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The app-facing entry point: resolve a configured connection to an adapter, and
 * run the parts of the listing pipeline that are the same on every marketplace.
 *
 * A service first, CLI second -- the commands exist so the pipeline can be driven
 * by hand while a seller is being set up, which is exactly when it goes wrong.
 */
final readonly class MarketplaceService
{
    /** @param array<string, array{driver: string, site: string, sandbox?: bool, options?: array<string, mixed>}> $connections */
    public function __construct(
        private MarketplaceRegistry $registry,
        private array $connections = [],
    ) {
    }

    public function adapter(string $connectionName): MarketplaceAdapterInterface
    {
        $config = $this->connections[$connectionName] ?? throw new MarketplaceConfigurationException(sprintf('No marketplace connection named "%s". Configured: %s.', $connectionName, [] === $this->connections ? '(none)' : implode(', ', array_keys($this->connections))));

        return $this->registry->create(new ConnectionConfiguration(
            name: $connectionName,
            driver: $config['driver'],
            site: $config['site'],
            sandbox: $config['sandbox'] ?? false,
            options: $config['options'] ?? [],
        ));
    }

    /**
     * Everything that must be true before publishing: the structural rules, the
     * provider's own limits, and the category's attribute contract.
     *
     * Runs entirely against data already fetched -- no publish attempt -- so it is
     * safe to call on every draft, including in a UI.
     *
     * @return list<ListingViolation>
     */
    public function validate(ListingDraft $draft, MarketplaceAdapterInterface $adapter, ?AttributeSchema $schema = null): array
    {
        if (null === $schema && null !== $draft->categoryId) {
            $schema = $adapter->attributeSchema($draft->categoryId);
        }

        return [
            ...$draft->violations($adapter->limits()),
            ...($schema?->violationsFor($draft) ?? []),
        ];
    }

    #[AsCommand('marketplace:categories', 'Suggest categories for a title, on one connection')]
    public function categoriesCommand(
        SymfonyStyle $io,
        #[Argument('Connection name')] string $connection,
        #[Argument('Listing title')] string $title,
    ): int {
        $adapter = $this->adapter($connection);
        $suggestions = $adapter->suggestCategories($title);

        if ([] === $suggestions) {
            $io->warning(sprintf('%s has no category suggestion for "%s".', $adapter->provider(), $title));

            return Command::SUCCESS;
        }

        $io->table(
            ['category', 'name', 'confidence', 'path'],
            array_map(static fn ($s): array => [
                $s->categoryId,
                $s->name,
                null !== $s->confidence ? sprintf('%.0f%%', $s->confidence * 100) : '-',
                $s->breadcrumb(),
            ], $suggestions),
        );

        return Command::SUCCESS;
    }

    #[AsCommand('marketplace:attributes', 'Show what a category requires, and the JSON Schema for it')]
    public function attributesCommand(
        SymfonyStyle $io,
        #[Argument('Connection name')] string $connection,
        #[Argument('Provider category id')] string $categoryId,
        #[Option('Emit the JSON Schema for structured model output')] bool $json = false,
    ): int {
        $schema = $this->adapter($connection)->attributeSchema($categoryId);

        if ($json) {
            $io->writeln((string) json_encode($schema->toJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Category %s: %d attributes, %d required', $categoryId, count($schema), count($schema->required())));
        $io->writeln($schema->describe());

        return Command::SUCCESS;
    }

    #[AsCommand('marketplace:connections', 'List configured marketplace connections')]
    public function connectionsCommand(SymfonyStyle $io): int
    {
        if ([] === $this->connections) {
            $io->warning('No connections configured under survos_marketplace.connections.');

            return Command::SUCCESS;
        }

        $io->table(
            ['name', 'driver', 'site', 'sandbox'],
            array_map(
                static fn (string $name, array $c): array => [
                    $name,
                    $c['driver'],
                    $c['site'],
                    ($c['sandbox'] ?? false) ? 'yes' : 'no',
                ],
                array_keys($this->connections),
                array_values($this->connections),
            ),
        );

        return Command::SUCCESS;
    }
}
