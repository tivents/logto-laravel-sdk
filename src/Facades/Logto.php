<?php

namespace TIVENTS\LogtoLaravelSdk\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array discoverOidcConfig() Discover OIDC configuration from Logto.
 * @method static array getOidcConfig() Get OIDC configuration (cached).
 * @method static string getAuthorizationUrl(string $state = null, string $nonce = null, string $redirectUri = null) Generate authorization URL for OIDC flow.
 * @method static array exchangeCodeForTokens(string $code) Exchange authorization code for tokens.
 * @method static array refreshToken(string $refreshToken) Refresh access token using refresh token.
 * @method static array getUserInfo(string $accessToken) Get user information using access token.
 * @method static array validateIdToken(string $idToken, string $nonce = null) Validate ID token.
 * @method static string logout(string $idToken = null, string $postLogoutRedirectUri = null) Logout user from Logto.
 * @method static ?string getAccessToken(string $userId) Get access token for a user.
 * @method static string getSdkSignInUrl(string $redirectUri, array $extraParams = []) Get sign-in URL using official SDK.
 * @method static array handleSdkCallback(string $redirectUri) Handle sign-in callback using official SDK.
 * @method static string getSdkSignOutUrl(?string $postLogoutRedirectUri, ?string $idTokenHint) Get sign-out URL using official SDK.
 * @method static array getSdkUserInfo() Get user info using official SDK.
 * @method static array refreshSdkAccessToken() Refresh access token using official SDK.
 * @method static void clearSdkSession() Clear the current authentication session.
 * @method static ?string getSdkAccessToken(?string $resource) Get the access token from SDK.
 * @method static ?string getSdkIdToken() Get the ID token from SDK.
 * @method static ?string getSdkRefreshToken() Get the refresh token from SDK.
 * @method static bool isSdkAuthenticated() Check if user is authenticated via SDK.
 * @method static array request(string $method, string $path, array $options = [], string $userId = null) Make authenticated API request.
 * @method static array getUserByAccessToken(string $accessToken) Get user by access token.
 * @method static array clientCredentialsFlow(array $scopes = []) Exchange client credentials for tokens.
 * @method static void storeTokens(string $userId, array $tokens) Store access and refresh tokens for a user.
 * @method static ?string getAccessTokenFromStore(string $userId) Get the access token for a user from store.
 * @method static ?string getRefreshTokenFromStore(string $userId) Get the refresh token for a user from store.
 * @method static void clearTokens(string $userId) Clear all tokens for a user.
 * @method static bool hasValidTokens(string $userId) Check if user has valid tokens.
 * @method static ?int getTokenExpiration(string $userId) Get token expiration time.
 * @method static string getTokenType(string $userId) Get token type.
 *
 * @see \TIVENTS\LogtoLaravelSdk\Services\LogtoClient
 * @see \TIVENTS\LogtoLaravelSdk\Services\TokenManager
 * @see \TIVENTS\LogtoLaravelSdk\Services\LogtoSdkAdapter
 */
class Logto extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'logto';
    }
}
