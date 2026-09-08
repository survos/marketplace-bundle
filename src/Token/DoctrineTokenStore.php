<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Token;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MarketplaceBundle\Entity\MarketplaceTokenRecord;
use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Model\MarketplaceToken;

/**
 * Tokens in the database. The production counterpart to FileTokenStore.
 *
 * FileTokenStore writes under var/, which is fine on a laptop and wrong anywhere
 * that redeploys or runs more than one process:
 *
 *  - a container filesystem is replaced on every deploy, so the seller
 *    re-authorizes after each push;
 *  - two web processes each keep their own copy, and a refresh on one leaves the
 *    other holding a token that has been rotated away underneath it.
 *
 * That second point is the dangerous one. Etsy access tokens last an hour, so
 * refreshes are constant, and Mercado Libre refresh tokens are single use --
 * two processes refreshing independently would permanently lock the account out.
 *
 * The write is a single flush of one row, so the "persist before use" requirement
 * in TokenStoreInterface holds: by the time save() returns, the replacement is
 * committed.
 */
final readonly class DoctrineTokenStore implements TokenStoreInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(string $connectionName): ?MarketplaceToken
    {
        $record = $this->em->find(MarketplaceTokenRecord::class, $connectionName);

        if (null === $record || '' === $record->accessToken) {
            return null;
        }

        return new MarketplaceToken(
            accessToken: $record->accessToken,
            accessTokenExpiresAt: $record->accessTokenExpiresAt,
            refreshToken: $record->refreshToken,
            refreshTokenExpiresAt: $record->refreshTokenExpiresAt,
            scopes: $record->scopes,
        );
    }

    public function save(string $connectionName, MarketplaceToken $token): void
    {
        $record = $this->em->find(MarketplaceTokenRecord::class, $connectionName)
            ?? new MarketplaceTokenRecord($connectionName);

        // Replaced wholesale, never merged: a rotated refresh token must not be
        // able to coexist with the one it replaced.
        $record->accessToken = $token->accessToken;
        $record->accessTokenExpiresAt = $token->accessTokenExpiresAt;
        $record->refreshToken = $token->refreshToken;
        $record->refreshTokenExpiresAt = $token->refreshTokenExpiresAt;
        $record->scopes = $token->scopes;
        $record->updatedAt = new \DateTimeImmutable();

        $this->em->persist($record);
        $this->em->flush();
    }

    public function delete(string $connectionName): void
    {
        $record = $this->em->find(MarketplaceTokenRecord::class, $connectionName);

        if (null !== $record) {
            $this->em->remove($record);
            $this->em->flush();
        }
    }
}
