<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Token;

use Survos\MarketplaceContracts\Contract\TokenStoreInterface;
use Survos\MarketplaceContracts\Model\MarketplaceToken;
use Survos\MercadoLibre\Auth\AccessTokenProviderInterface;
use Survos\MercadoLibre\Auth\MercadoLibreCredentials;
use Survos\MercadoLibre\Auth\MercadoLibreToken;
use Survos\MercadoLibre\Auth\OAuthService;
use Survos\MercadoLibre\Auth\RefreshingTokenProvider;
use Survos\MercadoLibre\MercadoLibreSite;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridges the neutral token store to Mercado Libre's own token type.
 *
 * The eBay bridge's twin, but with one asymmetry that matters: Mercado Libre
 * refresh tokens are SINGLE USE. RefreshingTokenProvider persists the replacement
 * before using it, and this bridge must not lose it in translation -- so the
 * neutral MarketplaceToken carries the rotated refresh token straight through,
 * with no fallback to the previous one.
 *
 * The seller's user id arrives only with the token, and every seller-scoped call
 * needs it, so it is round-tripped through the store's scopes-adjacent slot rather
 * than being refetched.
 */
final readonly class MercadoLibreTokenProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TokenStoreInterface $tokens,
        private MercadoLibreCredentials $credentials,
        private MercadoLibreSite $site = MercadoLibreSite::Mexico,
    ) {
    }

    public function forConnection(string $connectionName): AccessTokenProviderInterface
    {
        return new RefreshingTokenProvider(
            $this->oauth(),
            load: fn (): ?MercadoLibreToken => self::toMeli($this->tokens->get($connectionName)),
            persist: fn (MercadoLibreToken $token): null => $this->tokens->save(
                $connectionName,
                self::toNeutral($token),
            ),
        );
    }

    public function oauth(): OAuthService
    {
        return new OAuthService($this->httpClient, $this->credentials, $this->site);
    }

    public function store(): TokenStoreInterface
    {
        return $this->tokens;
    }

    public static function toNeutral(MercadoLibreToken $token): MarketplaceToken
    {
        return new MarketplaceToken(
            accessToken: $token->accessToken,
            accessTokenExpiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            // Mercado Libre does not report a refresh expiry; it is documented as
            // six months. Left null rather than invented -- canRefresh() then trusts
            // the token until Mercado Libre says otherwise, which is the honest
            // reading of "we were not told".
            refreshTokenExpiresAt: null,
            // The seller id has nowhere else to live in the neutral model, and it is
            // needed on every seller-scoped call.
            scopes: null !== $token->userId
                ? [...$token->scopes, 'user_id:'.$token->userId]
                : $token->scopes,
        );
    }

    private static function toMeli(?MarketplaceToken $token): ?MercadoLibreToken
    {
        if (null === $token) {
            return null;
        }

        $userId = null;
        $scopes = [];
        foreach ($token->scopes as $scope) {
            if (str_starts_with($scope, 'user_id:')) {
                $userId = (int) substr($scope, 8);

                continue;
            }
            $scopes[] = $scope;
        }

        return new MercadoLibreToken(
            accessToken: $token->accessToken,
            expiresAt: $token->accessTokenExpiresAt,
            refreshToken: $token->refreshToken,
            userId: $userId,
            scopes: $scopes,
        );
    }
}
