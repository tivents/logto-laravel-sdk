<?php

namespace TIVENTS\LogtoLaravelSdk\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

class TokenManager
{
    protected string $cachePrefix = 'logto_token_';
    
    protected int $accessTokenLifetime;
    
    protected int $refreshTokenLifetime;
    
    protected bool $storeEncrypted;

    public function __construct()
    {
        $this->accessTokenLifetime = (int) config('logto.tokens.access_token_lifetime', 3600);
        $this->refreshTokenLifetime = (int) config('logto.tokens.refresh_token_lifetime', 86400);
        $this->storeEncrypted = (bool) config('logto.tokens.store_encrypted', true);
    }

    /**
     * Store access and refresh tokens for a user.
     */
    public function storeTokens(string $userId, array $tokens): void
    {
        $key = $this->getCacheKey($userId);
        
        $data = [
            'access_token' => $this->storeEncrypted 
                ? Crypt::encrypt($tokens['access_token'])
                : $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'token_type' => $tokens['token_type'] ?? 'Bearer',
            'expires_in' => $tokens['expires_in'] ?? $this->accessTokenLifetime,
            'refresh_expires_in' => $tokens['refresh_expires_in'] ?? $this->refreshTokenLifetime,
            'issued_at' => time(),
        ];
        
        // Store refresh token separately with longer lifetime
        if (isset($tokens['refresh_token'])) {
            $refreshKey = $this->getRefreshCacheKey($userId);
            Cache::put($refreshKey, $this->storeEncrypted 
                ? Crypt::encrypt($tokens['refresh_token'])
                : $tokens['refresh_token'], 
                $this->refreshTokenLifetime);
        }
        
        Cache::put($key, $data, $tokens['expires_in'] ?? $this->accessTokenLifetime);
    }

    /**
     * Get the access token for a user.
     */
    public function getAccessToken(string $userId): ?string
    {
        $key = $this->getCacheKey($userId);
        $data = Cache::get($key);
        
        if (!$data) {
            return null;
        }
        
        $token = $this->storeEncrypted 
            ? Crypt::decrypt($data['access_token'])
            : $data['access_token'];
        
        // Check if token is expired
        if ($this->isTokenExpired($data)) {
            $this->clearTokens($userId);
            return null;
        }
        
        return $token;
    }

    /**
     * Get the refresh token for a user.
     */
    public function getRefreshToken(string $userId): ?string
    {
        $refreshKey = $this->getRefreshCacheKey($userId);
        $refreshToken = Cache::get($refreshKey);
        
        if (!$refreshToken) {
            return null;
        }
        
        return $this->storeEncrypted 
            ? Crypt::decrypt($refreshToken)
            : $refreshToken;
    }

    /**
     * Refresh the access token using the refresh token.
     * Tries to use SDK adapter first, falls back to client refreshToken method.
     */
    public function refreshAccessToken(string $userId, LogtoClient $client): array
    {
        // Try to use SDK adapter if available
        if ($client->hasSdkAdapter()) {
            try {
                $response = $client->refreshSdkAccessToken();
                $this->storeTokens($userId, $response);
                return $response;
            } catch (\Exception $e) {
                // Fall back to manual refresh
            }
        }

        $refreshToken = $this->getRefreshToken($userId);
        
        if (!$refreshToken) {
            throw LogtoException::authenticationFailed('No refresh token available');
        }
        
        $response = $client->refreshToken($refreshToken);
        $this->storeTokens($userId, $response);
        
        return $response;
    }

    /**
     * Store tokens from SDK adapter result.
     * This method ensures proper handling of tokens from the official SDK.
     */
    public function storeSdkTokens(string $userId, array $tokens, ?string $userInfo = null): void
    {
        // Normalize tokens array to match expected format
        $normalizedTokens = [
            'access_token' => $tokens['access_token'] ?? '',
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'token_type' => $tokens['token_type'] ?? 'Bearer',
            'expires_in' => $tokens['expires_in'] ?? $this->accessTokenLifetime,
        ];

        // If we have user info with ID token, we might extract expiration from there
        if ($userInfo && is_array($userInfo)) {
            // Try to get expiration from ID token claims if available
            if (isset($userInfo['id_token_claims']['exp'])) {
                $expiresAt = $userInfo['id_token_claims']['exp'];
                $normalizedTokens['expires_in'] = max(0, $expiresAt - time());
            }
        }

        $this->storeTokens($userId, $normalizedTokens);
    }

    /**
     * Clear all tokens for a user.
     */
    public function clearTokens(string $userId): void
    {
        $key = $this->getCacheKey($userId);
        $refreshKey = $this->getRefreshCacheKey($userId);
        
        Cache::forget($key);
        Cache::forget($refreshKey);
    }

    /**
     * Check if the token is expired.
     */
    protected function isTokenExpired(array $tokenData): bool
    {
        $issuedAt = $tokenData['issued_at'] ?? 0;
        $expiresIn = $tokenData['expires_in'] ?? $this->accessTokenLifetime;
        
        return (time() - $issuedAt) >= $expiresIn;
    }

    /**
     * Generate cache key for user tokens.
     */
    protected function getCacheKey(string $userId): string
    {
        return $this->cachePrefix . $userId;
    }

    /**
     * Generate cache key for refresh tokens.
     */
    protected function getRefreshCacheKey(string $userId): string
    {
        return $this->cachePrefix . 'refresh_' . $userId;
    }

    /**
     * Get token expiration time.
     */
    public function getTokenExpiration(string $userId): ?int
    {
        $key = $this->getCacheKey($userId);
        $data = Cache::get($key);
        
        if (!$data) {
            return null;
        }
        
        return ($data['issued_at'] ?? 0) + ($data['expires_in'] ?? $this->accessTokenLifetime);
    }

    /**
     * Validate if user has valid tokens.
     */
    public function hasValidTokens(string $userId): bool
    {
        return $this->getAccessToken($userId) !== null;
    }

    /**
     * Get token type (default: Bearer).
     */
    public function getTokenType(string $userId): string
    {
        $key = $this->getCacheKey($userId);
        $data = Cache::get($key);
        
        return $data['token_type'] ?? 'Bearer';
    }
}
