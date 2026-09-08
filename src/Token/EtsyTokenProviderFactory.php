<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Token;

use Survos\Etsy\Auth\AccessTokenProviderInterface;
use Survos\Etsy\Auth\EtsyCredentials;
use Survos\Etsy\Auth\EtsyToken;
use Survos\Etsy\Auth\OAuthService;
use Survos\Etsy\Auth\RefreshingTokenProvider;
use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Model\MarketplaceToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridges the neutral token store to Etsy's own token type.
 *
 * Takes credentials per call rather than holding them, because Etsy credentials
 * are per-seller — see {@see \Survos\MarketplaceBundle\Adapter\EtsyAdapterFactory}.
 *
 * Etsy does NOT rotate refresh tokens, so unlike Mercado Libre a persist failure
 * here is recoverable: the previous refresh token still works. The ninety-day
 * expiry is the real deadline, and nothing warns you before it passes.
 */
final readonly class EtsyTokenProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TokenStoreInterface $tokens,
    ) {
    }

    public function forConnection(string $connectionName, EtsyCredentials $credentials): AccessTokenProviderInterface
    {
        $oauth = $this->oauth($credentials);

        return new RefreshingTokenProvider(
            $oauth,
            load: fn (): ?EtsyToken => self::toEtsy($this->tokens->get($connectionName)),
            persist: fn (EtsyToken $token): null => $this->tokens->save($connectionName, self::toNeutral($token)),
        );
    }

    public function oauth(EtsyCredentials $credentials): OAuthService
    {
        return new OAuthService($this->httpClient, $credentials);
    }

    public function store(): TokenStoreInterface
    {
        return $this->tokens;
    }

    public static function toNeutral(EtsyToken $token): MarketplaceToken
    {
        return new MarketplaceToken(
            accessToken: $token->accessToken,
            accessTokenExpiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            // Etsy does not report it; ninety days is documented but not returned.
            refreshTokenExpiresAt: null,
            scopes: $token->scopes,
        );
    }

    private static function toEtsy(?MarketplaceToken $token): ?EtsyToken
    {
        // The seller's user id is the token's own prefix, so EtsyToken recovers it
        // without needing anywhere to stash it.
        return null === $token ? null : EtsyToken::fromResponse([
            'access_token' => $token->accessToken,
            'expires_in' => max(0, $token->accessTokenExpiresAt->getTimestamp() - time()),
            'refresh_token' => $token->refreshToken,
            'scope' => implode(' ', $token->scopes),
        ]);
    }
}
