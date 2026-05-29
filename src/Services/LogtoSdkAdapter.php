<?php

namespace TIVENTS\LogtoLaravelSdk\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Logto\Sdk\LogtoClient as LogtoSdkClient;
use Logto\Sdk\LogtoConfig;
use Logto\Sdk\Oidc\OidcCore;
use Logto\Sdk\Storage\Storage;
use Logto\Sdk\Storage\StorageKey;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

/**
 * Adapter to integrate the official Logto SDK with Laravel.
 * This provides a bridge between the Logto PHP SDK and Laravel's infrastructure.
 *
 * @package TIVENTS\LogtoLaravelSdk\Services
 */
class LogtoSdkAdapter implements Storage
{
    /**
     * The token manager instance.
     */
    protected TokenManager $tokenManager;

    /**
     * Cached SDK client instance.
     */
    protected ?LogtoSdkClient $sdkClient = null;

    /**
     * Create a new adapter instance.
     */
    public function __construct(TokenManager $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    /**
     * Create and return the official Logto SDK client instance.
     * Uses lazy initialization to avoid unnecessary network requests.
     *
     * @return LogtoSdkClient
     * @throws LogtoException
     */
    public function getSdkClient(): LogtoSdkClient
    {
        if ($this->sdkClient === null) {
            // Set reasonable memory limit for SDK operations
            $oldMemoryLimit = ini_get('memory_limit');
            @ini_set('memory_limit', '256M');
            
            try {
                $config = $this->createLogtoConfig();
                $this->sdkClient = new LogtoSdkClient($config, $this);
            } finally {
                // Always restore memory limit
                @ini_set('memory_limit', $oldMemoryLimit ?? '128M');
            }
        }

        return $this->sdkClient;
    }

    /**
     * Reset the SDK client instance (useful for testing or reconfiguration).
     */
    public function resetSdkClient(): void
    {
        $this->sdkClient = null;
    }

    /**
     * Create Logto configuration from Laravel config.
     *
     * @return LogtoConfig
     * @throws LogtoException
     */
    protected function createLogtoConfig(): LogtoConfig
    {
        $appId = config('logto.app_id');
        $appSecret = config('logto.app_secret');
        $endpoint = rtrim((string) config('logto.endpoint'), '/');
        $scopes = config('logto.oidc.scopes', ['openid', 'profile', 'email']);
        $resources = config('logto.oidc.resources', []);
        $prompt = config('logto.oidc.prompt', 'consent');

        // Validate required configuration
        if (empty($appId)) {
            throw LogtoException::configurationError('LOGTO_APP_ID is not configured');
        }

        if (empty($appSecret)) {
            throw LogtoException::configurationError('LOGTO_APP_SECRET is not configured');
        }

        if (empty($endpoint)) {
            throw LogtoException::configurationError('LOGTO_ENDPOINT is not configured');
        }

        // Map Laravel config prompt to Logto SDK Prompt enum
        // The Prompt enum is defined in the LogtoConfig file
        $promptValue = match (strtolower($prompt)) {
            'login' => 'login',
            'consent' => 'consent',
            default => 'consent',
        };

        // Create config - Prompt enum will be handled by LogtoConfig
        return new LogtoConfig(
            endpoint: $endpoint,
            appId: $appId,
            appSecret: $appSecret,
            scopes: $scopes,
            resources: $resources
        );
    }

    /**
     * Get sign-in URL using the official SDK.
     *
     * @param string $redirectUri The redirect URI after authentication
     * @param array $extraParams Additional query parameters
     * @return string
     */
    public function getSignInUrl(string $redirectUri, array $extraParams = []): string
    {
        try {
            $client = $this->getSdkClient();
            return $client->signIn($redirectUri, extraParams: $extraParams);
        } catch (\Exception $e) {
            Log::error('Failed to generate sign-in URL: ' . $e->getMessage());
            throw LogtoException::authenticationFailed('Failed to generate sign-in URL: ' . $e->getMessage());
        }
    }

    /**
     * Handle sign-in callback using the official SDK.
     *
     * @param string $redirectUri The expected redirect URI
     * @return array{tokens: array, user_info: array}
     */
    public function handleSignInCallback(string $redirectUri): array
    {
        try {
            // Set memory limit for callback processing
            $oldMemoryLimit = ini_get('memory_limit');
            @ini_set('memory_limit', '256M');
            
            try {
                $client = $this->getSdkClient();

                // Store the redirect URI for validation in the SDK
                Session::put('logto_sdk_redirect_uri', $redirectUri);

                // Handle the callback - this will validate state, exchange code, etc.
                $client->handleSignInCallback();

                // Get tokens and user info
                $idToken = $client->getIdToken();
                $accessToken = $client->getAccessToken();
                $refreshToken = $client->getRefreshToken();

                $tokens = array_filter([
                    'id_token' => $idToken,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_type' => 'Bearer',
                ]);

                // Get user information
                $userInfo = $this->getUserInfo();

                return [
                    'tokens' => $tokens,
                    'user_info' => $userInfo,
                ];
            } finally {
                @ini_set('memory_limit', $oldMemoryLimit ?? '128M');
            }
        } catch (\Exception $e) {
            @ini_set('memory_limit', $oldMemoryLimit ?? '128M');
            Log::error('Failed to handle sign-in callback: ' . $e->getMessage());
            throw LogtoException::authenticationFailed('Failed to handle sign-in callback: ' . $e->getMessage());
        }
    }

    /**
     * Get sign-out URL using the official SDK.
     *
     * @param string|null $postLogoutRedirectUri The URI to redirect to after logout
     * @param string|null $idTokenHint The ID token for logout hint
     * @return string
     */
    public function getSignOutUrl(?string $postLogoutRedirectUri = null, ?string $idTokenHint = null): string
    {
        try {
            $postLogoutRedirectUri ??= config('logto.oidc.post_logout_redirect_uri', '/');

            // We'll build the URL manually to include the id_token_hint
            $config = $this->createLogtoConfig();
            $oidcCore = OidcCore::create(rtrim($config->endpoint, "/"));

            $endSessionEndpoint = $oidcCore->metadata->end_session_endpoint;

            if (!$endSessionEndpoint) {
                // Fallback to standard endpoint
                $endSessionEndpoint = $config->endpoint . '/oidc/session/end';
            }

            $query = [
                'client_id' => $config->appId,
                'post_logout_redirect_uri' => $postLogoutRedirectUri,
            ];

            if ($idTokenHint) {
                $query['id_token_hint'] = $idTokenHint;
            }

            return $endSessionEndpoint . '?' . http_build_query($query);
        } catch (\Exception $e) {
            Log::error('Failed to generate sign-out URL: ' . $e->getMessage());
            throw LogtoException::authenticationFailed('Failed to generate sign-out URL: ' . $e->getMessage());
        }
    }

    /**
     * Get user information using the official SDK.
     *
     * @return array
     */
    public function getUserInfo(): array
    {
        try {
            $client = $this->getSdkClient();
            $userInfo = $client->fetchUserInfo();

            // Convert UserInfoResponse to array
            $userInfoArray = (array) $userInfo;

            // Add ID token claims if available
            $idToken = $client->getIdToken();
            if ($idToken) {
                try {
                    $idTokenClaims = $client->getIdTokenClaims();
                    $userInfoArray['id_token_claims'] = (array) $idTokenClaims;
                } catch (\Exception $e) {
                    Log::warning('Failed to decode ID token claims: ' . $e->getMessage());
                }
            }

            return $userInfoArray;
        } catch (\Exception $e) {
            Log::error('Failed to get user info: ' . $e->getMessage());
            throw LogtoException::apiError('Failed to get user info: ' . $e->getMessage());
        }
    }

    /**
     * Refresh access token using the official SDK.
     *
     * @return array
     */
    public function refreshAccessToken(): array
    {
        try {
            $client = $this->getSdkClient();

            // Force refresh by getting a new access token
            // The SDK automatically refreshes if expired
            $accessToken = $client->getAccessToken();
            $refreshToken = $client->getRefreshToken();

            return array_filter([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600, // Will be recalculated by TokenManager
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to refresh access token: ' . $e->getMessage());
            throw LogtoException::apiError('Failed to refresh access token: ' . $e->getMessage());
        }
    }

    /**
     * Clear the current authentication session.
     */
    public function clearSession(): void
    {
        try {
            // Clear SDK storage
            foreach (StorageKey::cases() as $key) {
                $this->delete($key);
            }

            // Also clear Laravel session keys
            Session::forget(['logto_tokens', 'logto_user_info', 'logto_sdk_']);
        } catch (\Exception $e) {
            Log::error('Failed to clear session: ' . $e->getMessage());
        }
    }

    /**
     * Get the token manager instance.
     *
     * @return TokenManager
     */
    public function getTokenManager(): TokenManager
    {
        return $this->tokenManager;
    }

    /**
     * Get the access token from the SDK.
     *
     * @param string|null $resource The resource to get the token for
     * @return string|null
     */
    public function getSdkAccessToken(?string $resource = null): ?string
    {
        try {
            $client = $this->getSdkClient();
            return $client->getAccessToken($resource ?? '');
        } catch (\Exception $e) {
            Log::error('Failed to get access token from SDK: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the ID token from the SDK.
     *
     * @return string|null
     */
    public function getSdkIdToken(): ?string
    {
        try {
            $client = $this->getSdkClient();
            return $client->getIdToken();
        } catch (\Exception $e) {
            Log::error('Failed to get ID token from SDK: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the refresh token from the SDK.
     *
     * @return string|null
     */
    public function getSdkRefreshToken(): ?string
    {
        try {
            $client = $this->getSdkClient();
            return $client->getRefreshToken();
        } catch (\Exception $e) {
            Log::error('Failed to get refresh token from SDK: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if the user is authenticated via the SDK.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        try {
            $client = $this->getSdkClient();
            return $client->isAuthenticated();
        } catch (\Exception $e) {
            Log::error('Failed to check authentication status: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // Storage Interface Implementation for Logto SDK
    // ========================================================================

    /**
     * Get a value from storage.
     *
     * @param StorageKey $key The storage key
     * @return string|null
     */
    public function get(StorageKey $key): ?string
    {
        $sessionKey = $this->mapStorageKey($key->value);
        return Session::get($sessionKey);
    }

    /**
     * Set a value in storage.
     *
     * @param StorageKey $key The storage key
     * @param string|null $value The value to store
     */
    public function set(StorageKey $key, ?string $value): void
    {
        $sessionKey = $this->mapStorageKey($key->value);
        if ($value === null) {
            Session::forget($sessionKey);
        } else {
            Session::put($sessionKey, $value);
        }
    }

    /**
     * Delete a value from storage.
     *
     * @param StorageKey $key The storage key
     */
    public function delete(StorageKey $key): void
    {
        $sessionKey = $this->mapStorageKey($key->value);
        Session::forget($sessionKey);
    }

    /**
     * Map Logto SDK storage keys to Laravel session keys.
     *
     * @param string $key The SDK storage key
     * @return string
     */
    protected function mapStorageKey(string $key): string
    {
        return 'logto_sdk_' . $key;
    }

    /**
     * Get the sign-in session from storage as array.
     *
     * @return array|null The sign-in session data
     */
    public function getSignInSession(): ?array
    {
        $data = $this->get(StorageKey::signInSession);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Set the sign-in session in storage.
     *
     * @param array $session The sign-in session data
     */
    public function setSignInSession(array $session): void
    {
        $this->set(StorageKey::signInSession, json_encode($session));
    }

    /**
     * Generate a code verifier for PKCE.
     *
     * @return string
     */
    public function generateCodeVerifier(): string
    {
        $oidcCore = OidcCore::create(rtrim(config('logto.endpoint'), "/"));
        return $oidcCore::generateCodeVerifier();
    }

    /**
     * Generate a code challenge from a verifier for PKCE.
     *
     * @param string $codeVerifier The code verifier
     * @return string
     */
    public function generateCodeChallenge(string $codeVerifier): string
    {
        $oidcCore = OidcCore::create(rtrim(config('logto.endpoint'), "/"));
        return $oidcCore::generateCodeChallenge($codeVerifier);
    }

    /**
     * Generate a state for CSRF protection.
     *
     * @return string
     */
    public function generateState(): string
    {
        $oidcCore = OidcCore::create(rtrim(config('logto.endpoint'), "/"));
        return $oidcCore::generateState();
    }
}
