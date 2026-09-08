<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Token;

use Survos\Ebay\Auth\AccessTokenProviderInterface;
use Survos\Ebay\Auth\EbayCredentials;
use Survos\Ebay\Auth\EbayToken;
use Survos\Ebay\Auth\OAuthService;
use Survos\Ebay\Auth\RefreshingTokenProvider;
use Survos\Ebay\EbayEnvironment;
use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Model\MarketplaceToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridges the neutral token store to eBay's own token type.
 *
 * survos/ebay-php deliberately does not depend on survos/marketplace-contracts --
 * it stands alone -- so something has to translate, and this is the seam where that
 * belongs. Neither library learns about the other.
 */
final readonly class EbayTokenProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TokenStoreInterface $tokens,
        private EbayCredentials $credentials,
        private EbayEnvironment $environment = EbayEnvironment::Sandbox,
    ) {
    }

    public function forConnection(string $connectionName): AccessTokenProviderInterface
    {
        $oauth = new OAuthService($this->httpClient, $this->credentials, $this->environment);

        return new RefreshingTokenProvider(
            $oauth,
            load: fn (): ?EbayToken => self::toEbay($this->tokens->get($connectionName)),
            persist: fn (EbayToken $token): null => $this->tokens->save(
                $connectionName,
                self::toNeutral($token),
            ),
        );
    }

    public function oauth(): OAuthService
    {
        return new OAuthService($this->httpClient, $this->credentials, $this->environment);
    }

    public function store(): TokenStoreInterface
    {
        return $this->tokens;
    }

    public static function toNeutral(EbayToken $token): MarketplaceToken
    {
        return new MarketplaceToken(
            accessToken: $token->accessToken,
            accessTokenExpiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            refreshTokenExpiresAt: $token->refreshTokenExpiresAt,
            scopes: $token->scopes,
        );
    }

    private static function toEbay(?MarketplaceToken $token): ?EbayToken
    {
        return null === $token ? null : new EbayToken(
            accessToken: $token->accessToken,
            expiresAt: $token->accessTokenExpiresAt,
            refreshToken: $token->refreshToken,
            refreshTokenExpiresAt: $token->refreshTokenExpiresAt,
            scopes: $token->scopes,
        );
    }
}
