<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MarketplaceBundle\Repository\MarketplaceTokenRepository;

/**
 * One seller's OAuth token, in the database.
 *
 * The row is keyed by connection name, because that is what identifies a seller:
 * two connections can share a provider (chijal and marcela are both Mercado Libre
 * MLM) and must not share a token.
 */
#[ORM\Entity(repositoryClass: MarketplaceTokenRepository::class)]
#[ORM\Table(name: 'marketplace_token')]
class MarketplaceTokenRecord
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $connectionName;

    #[ORM\Column(type: Types::TEXT)]
    public string $accessToken = '';

    #[ORM\Column]
    public \DateTimeImmutable $accessTokenExpiresAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $refreshToken = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $refreshTokenExpiresAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $scopes = [];

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
        $this->accessTokenExpiresAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
