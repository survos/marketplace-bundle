<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Token;

use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Model\MarketplaceToken;

/**
 * Tokens as JSON files, one per connection.
 *
 * Deliberately the simplest thing that satisfies the contract's hard requirement:
 * save() replaces the refresh token in full and writes it before the caller uses
 * it. That matters because Mercado Libre rotates refresh tokens on every use and a
 * lost write locks the account out permanently.
 *
 * The write is atomic -- a temp file plus rename -- so a crash mid-write leaves the
 * previous token intact rather than a truncated file that parses as "no token".
 *
 * Fine for one seller on one host, which is what priceit is. A deployment with
 * several workers writing concurrently, or with an ephemeral filesystem, wants a
 * database-backed store instead; that is why this is an interface.
 */
final readonly class FileTokenStore implements TokenStoreInterface
{
    public function __construct(private string $directory)
    {
    }

    public function get(string $connectionName): ?MarketplaceToken
    {
        $file = $this->path($connectionName);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_at'])) {
            return null;
        }

        return new MarketplaceToken(
            accessToken: (string) $data['access_token'],
            accessTokenExpiresAt: new \DateTimeImmutable((string) $data['expires_at']),
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            refreshTokenExpiresAt: isset($data['refresh_expires_at'])
                ? new \DateTimeImmutable((string) $data['refresh_expires_at'])
                : null,
            scopes: array_values(array_filter(
                (array) ($data['scopes'] ?? []),
                static fn (mixed $s): bool => is_string($s),
            )),
        );
    }

    public function save(string $connectionName, MarketplaceToken $token): void
    {
        $file = $this->path($connectionName);
        $dir = \dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create token directory "%s".', $dir));
        }

        $payload = json_encode([
            'access_token' => $token->accessToken,
            'expires_at' => $token->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $token->refreshToken,
            'refresh_expires_at' => $token->refreshTokenExpiresAt?->format(\DateTimeInterface::ATOM),
            'scopes' => $token->scopes,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === file_put_contents($temp, $payload)) {
            throw new \RuntimeException(sprintf('Cannot write token file "%s".', $temp));
        }

        @chmod($temp, 0o600);

        if (!rename($temp, $file)) {
            @unlink($temp);

            throw new \RuntimeException(sprintf('Cannot replace token file "%s".', $file));
        }
    }

    public function delete(string $connectionName): void
    {
        $file = $this->path($connectionName);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function path(string $connectionName): string
    {
        // Connection names come from config, but a traversal here would write
        // outside the token directory, so normalize rather than trust.
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $connectionName) ?? 'default';

        return rtrim($this->directory, '/').'/'.$safe.'.json';
    }
}
