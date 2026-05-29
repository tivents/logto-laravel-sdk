<?php

namespace TIVENTS\LogtoLaravelSdk\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Logto\Sdk\Oidc\OidcCore;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

class LogtoClient
{
    protected ?PendingRequest $httpClient = null;
    
    protected string $appId;
    
    protected string $appSecret;
    
    protected string $endpoint;
    
    protected array $oidcConfig;
    
    protected ?LogtoSdkAdapter $sdkAdapter = null;

    public function __construct(protected TokenManager $tokenManager, ?LogtoSdkAdapter $sdkAdapter = null)
    {
        $this->appId = config('logto.app_id');
        $this->appSecret = config('logto.app_secret');
        $this->endpoint = rtrim((string) config('logto.endpoint'), '/');
        $this->oidcConfig = config('logto.oidc', []);
        $this->sdkAdapter = $sdkAdapter;
        
        $this->httpClient = Http::withOptions([
            'base_uri' => $this->endpoint,
            'timeout' => config('logto.http.timeout', 30),
            'connect_timeout' => config('logto.http.connect_timeout', 10),
            'verify' => config('logto.http.verify', true),
        ]);
    }

    /**
     * Get the SDK adapter instance.
     */
    public function getSdkAdapter(): ?LogtoSdkAdapter
    {
        return $this->sdkAdapter;
    }

    /**
     * Get the TokenManager instance.
     */
    public function getTokenManager(): TokenManager
    {
        return $this->tokenManager;
    }

    /**
     * Set the SDK adapter instance.
     */
    public function setSdkAdapter(LogtoSdkAdapter $sdkAdapter): void
    {
        $this->sdkAdapter = $sdkAdapter;
    }

    /**
     * Check if SDK adapter is available.
     */
    public function hasSdkAdapter(): bool
    {
        return $this->sdkAdapter !== null;
    }

    /**
     * Get sign-in URL using the official SDK.
     *
     * @param string $redirectUri The redirect URI after authentication
     * @param array $extraParams Additional query parameters
     * @return string
     */
    public function getSdkSignInUrl(string $redirectUri, array $extraParams = []): string
    {
        if (!$this->sdkAdapter) {
            throw LogtoException::configurationError('SDK adapter not available');
        }
        return $this->sdkAdapter->getSignInUrl($redirectUri, $extraParams);
    }

    /**
     * Handle sign-in callback using the official SDK.
     *
     * @param string $redirectUri The expected redirect URI
     * @return array{tokens: array, user_info: array}
     */
    public function handleSdkCallback(string $redirectUri): array
    {
        if (!$this->sdkAdapter) {
            throw LogtoException::configurationError('SDK adapter not available');
        }
        return $this->sdkAdapter->handleSignInCallback($redirectUri);
    }

    /**
     * Get sign-out URL using the official SDK.
     *
     * @param string|null $postLogoutRedirectUri The URI to redirect to after logout
     * @param string|null $idTokenHint The ID token for logout hint
     * @return string
     */
    public function getSdkSignOutUrl(?string $postLogoutRedirectUri = null, ?string $idTokenHint = null): string
    {
        if (!$this->sdkAdapter) {
            throw LogtoException::configurationError('SDK adapter not available');
        }
        return $this->sdkAdapter->getSignOutUrl($postLogoutRedirectUri, $idTokenHint);
    }

    /**
     * Get user information using the official SDK.
     *
     * @return array
     */
    public function getSdkUserInfo(): array
    {
        if (!$this->sdkAdapter) {
            throw LogtoException::configurationError('SDK adapter not available');
        }
        return $this->sdkAdapter->getUserInfo();
    }

    /**
     * Refresh access token using the official SDK.
     *
     * @return array
     */
    public function refreshSdkAccessToken(): array
    {
        if (!$this->sdkAdapter) {
            throw LogtoException::configurationError('SDK adapter not available');
        }
        return $this->sdkAdapter->refreshAccessToken();
    }

    /**
     * Clear the current authentication session.
     */
    public function clearSdkSession(): void
    {
        if ($this->sdkAdapter) {
            $this->sdkAdapter->clearSession();
        }
    }

    /**
     * Get the access token from SDK.
     *
     * @param string|null $resource The resource to get the token for
     * @return string|null
     */
    public function getSdkAccessToken(?string $resource = null): ?string
    {
        if (!$this->sdkAdapter) {
            return null;
        }
        return $this->sdkAdapter->getSdkAccessToken($resource);
    }

    /**
     * Get the ID token from SDK.
     *
     * @return string|null
     */
    public function getSdkIdToken(): ?string
    {
        if (!$this->sdkAdapter) {
            return null;
        }
        return $this->sdkAdapter->getSdkIdToken();
    }

    /**
     * Get the refresh token from SDK.
     *
     * @return string|null
     */
    public function getSdkRefreshToken(): ?string
    {
        if (!$this->sdkAdapter) {
            return null;
        }
        return $this->sdkAdapter->getSdkRefreshToken();
    }

    /**
     * Check if user is authenticated via SDK.
     *
     * @return bool
     */
    public function isSdkAuthenticated(): bool
    {
        if (!$this->sdkAdapter) {
            return false;
        }
        return $this->sdkAdapter->isAuthenticated();
    }

    /**
     * Discover OIDC configuration from Logto.
     * Uses the official SDK if available, otherwise falls back to direct HTTP request.
     */
    public function discoverOidcConfig(): array
    {
        // Try to use SDK adapter first
        if ($this->sdkAdapter) {
            try {
                $endpoint = rtrim(config('logto.endpoint'), '/');
                $oidcCore = OidcCore::create($endpoint);
                
                // The OIDC metadata contains the configuration
                $config = (array) $oidcCore->metadata;
                
                // Cache the configuration
                cache()->put('logto_oidc_config', $config, 86400); // 24 hours
                
                return $config;
            } catch (\Exception $e) {
                Log::warning('SDK-based OIDC discovery failed, falling back to HTTP: ' . $e->getMessage());
            }
        }

        // Fallback to direct HTTP request
        return $this->discoverOidcConfigLegacy();
    }

    /**
     * Legacy method for OIDC discovery (direct HTTP request).
     */
    protected function discoverOidcConfigLegacy(): array
    {
        try {
            if (!empty($this->oidcConfig['discovery_endpoint'])) {
                $url = $this->stripBaseUrl($this->oidcConfig['discovery_endpoint']);
            } else {
                $url = '/oidc/.well-known/openid-configuration';
            }
            
            $response = $this->httpClient->get($url);
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'Failed to discover OIDC configuration: ' . $response->body(),
                    $response->status()
                );
            }
            
            $config = $response->json();
            
            // Cache the configuration
            cache()->put('logto_oidc_config', $config, 86400); // 24 hours
            
            return $config;
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Get OIDC configuration (cached).
     */
    public function getOidcConfig(): array
    {
        return cache()->remember('logto_oidc_config', 86400, fn() => $this->discoverOidcConfig());
    }

    /**
     * Generate authorization URL for OIDC flow.
     * Uses the official SDK if available, otherwise falls back to manual URL generation.
     */
    public function getAuthorizationUrl(?string $state = null, ?string $nonce = null, ?string $redirectUri = null): string
    {
        // Try to use SDK adapter first
        if ($this->sdkAdapter) {
            try {
                $sdkRedirectUri = $redirectUri ?? url($this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback');
                return $this->sdkAdapter->getSignInUrl($sdkRedirectUri);
            } catch (\Exception $e) {
                Log::warning('SDK-based authorization URL generation failed, falling back to manual: ' . $e->getMessage());
            }
        }

        // Fallback to manual URL generation
        return $this->getAuthorizationUrlLegacy($state, $nonce, $redirectUri);
    }

    /**
     * Legacy method for generating authorization URL (manual generation).
     */
    public function getAuthorizationUrlLegacy(?string $state = null, ?string $nonce = null, ?string $redirectUri = null): string
    {
        $config = $this->getOidcConfig();
        
        $authorizationEndpoint = $config['authorization_endpoint'] 
            ?? $this->oidcConfig['authorization_endpoint'] 
            ?? $this->endpoint . '/oidc/auth';
        
        $sdkRedirectUri = $redirectUri ?? ($this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback');
        $scopes = $this->oidcConfig['scopes'] ?? ['openid', 'profile', 'email'];
        
        // Ensure 'openid' scope is present when using nonce parameter (OIDC requirement)
        if (!in_array('openid', $scopes)) {
            $scopes[] = 'openid';
        }
        $scopes = implode(' ', $scopes);
        
        // Generate state and nonce for CSRF protection
        $state ??= Str::random(40);
        $nonce ??= Str::random(40);
        
        // Store state and nonce in session
        session()->put('logto_state', $state);
        session()->put('logto_nonce', $nonce);
        
        $query = http_build_query([
            'client_id' => $this->appId,
            'response_type' => 'code',
            'scope' => $scopes,
            'redirect_uri' => url($sdkRedirectUri),
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'consent', // Optional: force consent screen
        ]);
        
        // Add PKCE support
        if ($this->oidcConfig['pkce'] ?? true) {
            $codeVerifier = Str::random(64);
            $codeChallenge = $this->generateCodeChallenge($codeVerifier);
            
            session()->put('logto_code_verifier', $codeVerifier);
            
            $query .= '&code_challenge=' . $codeChallenge . '&code_challenge_method=S256';
        }
        
        return $authorizationEndpoint . '?' . $query;
    }

    /**
     * Exchange authorization code for tokens.
     * Uses the official SDK if available, otherwise falls back to manual token exchange.
     */
    public function exchangeCodeForTokens(string $code): array
    {
        // Try to use SDK adapter first
        if ($this->sdkAdapter) {
            try {
                $redirectUri = url($this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback');
                $result = $this->sdkAdapter->handleSignInCallback($redirectUri);
                return $result['tokens'];
            } catch (\Exception $e) {
                Log::warning('SDK-based token exchange failed, falling back to manual: ' . $e->getMessage());
            }
        }

        // Fallback to manual token exchange
        return $this->exchangeCodeForTokensLegacy($code);
    }

    /**
     * Legacy method for exchanging code for tokens (manual HTTP request).
     */
    protected function exchangeCodeForTokensLegacy(string $code): array
    {
        try {
            $config = $this->getOidcConfig();
            
            // Extract relative path from token endpoint URL for proper HTTP client usage
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? '/oidc/token';
            
            // Remove base URI from endpoint if present (to avoid double URLs)
            $tokenEndpoint = $this->stripBaseUrl($tokenEndpoint);
            
            $redirectUri = $this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback';
            
            // Validate required configuration
            if (empty($this->appId) || empty($this->appSecret)) {
                throw LogtoException::configurationError(
                    'Logto app_id and app_secret must be configured in your .env file'
                );
            }
            
            $params = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => url($redirectUri),
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
            ];
            
            // Add PKCE code verifier
            if (session()->has('logto_code_verifier')) {
                $params['code_verifier'] = session()->pull('logto_code_verifier');
            }
            
            // Try with Basic Auth + client credentials in body (some OIDC providers require both)
            $response = $this->httpClient->withBasicAuth($this->appId, $this->appSecret)
                ->post($tokenEndpoint, $params);
            
            if ($response->failed()) {
                $errorData = $response->json() ?? [];
                $errorMessage = 'Failed to exchange code for tokens';
                
                if (!empty($errorData['error_description'])) {
                    $errorMessage .= ': ' . $errorData['error_description'];
                } elseif (!empty($errorData['message'])) {
                    $errorMessage .= ': ' . $errorData['message'];
                } else {
                    $errorMessage .= ': ' . $response->body();
                }
                
                // Add debug information
                $errorMessage .= '. Debug: code=' . substr($code, 0, 8) . '..., redirect_uri=' . $redirectUri
                    . ', client_id=' . substr($this->appId, 0, 8) . '...';
                
                throw LogtoException::apiError($errorMessage, $response->status());
            }
            
            $data = $response->json();
            
            // Validate required fields
            if (empty($data['access_token'])) {
                throw LogtoException::authenticationFailed('No access token in response');
            }
            
            return $data;
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Refresh access token using refresh token.
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            $config = $this->getOidcConfig();
            
            // Extract relative path from token endpoint URL
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? '/oidc/token';
            
            // Remove base URI from endpoint if present (to avoid double URLs)
            $tokenEndpoint = $this->stripBaseUrl($tokenEndpoint);
            
            // Try with Basic Auth + client credentials in body (some OIDC providers require both)
            $response = $this->httpClient->withBasicAuth($this->appId, $this->appSecret)
                ->post($tokenEndpoint, [
                    'form_params' => [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'client_id' => $this->appId,
                        'client_secret' => $this->appSecret,
                    ],
                ]);
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'Failed to refresh token: ' . $response->body(),
                    $response->status()
                );
            }
            
            return $response->json();
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Get user information using access token.
     * Uses the official SDK if available, otherwise falls back to manual HTTP request.
     */
    public function getUserInfo(string $accessToken): array
    {
        // Try to use SDK adapter first
        if ($this->sdkAdapter) {
            try {
                return $this->sdkAdapter->getUserInfo();
            } catch (\Exception $e) {
                Log::warning('SDK-based user info fetch failed, falling back to manual: ' . $e->getMessage());
            }
        }

        // Fallback to manual HTTP request
        return $this->getUserInfoLegacy($accessToken);
    }

    /**
     * Legacy method for getting user info (manual HTTP request).
     */
    protected function getUserInfoLegacy(string $accessToken): array
    {
        try {
            $config = $this->getOidcConfig();
            
            $userinfoEndpoint = $this->stripBaseUrl(
                $config['userinfo_endpoint'] 
                    ?? $this->oidcConfig['userinfo_endpoint'] 
                    ?? '/oidc/me'
            );
            
            $response = $this->httpClient->get($userinfoEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'Failed to get user info: ' . $response->body(),
                    $response->status()
                );
            }
            
            return $response->json();
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Get JWKS with caching.
     */
    protected function getJwks(): array
    {
        $cacheKey = 'logto_jwks_' . md5($this->endpoint);
        
        return cache()->remember($cacheKey, 86400, function () {
            $config = $this->getOidcConfig();
            $jwksUri = $this->stripBaseUrl(
                $config['jwks_uri'] ?? $this->oidcConfig['jwks_uri'] ?? '/oidc/jwks'
            );
            
            // Fetch JWKS with extended timeout
            $jwksResponse = $this->httpClient
                ->withOptions(['timeout' => 60, 'connect_timeout' => 30])
                ->get($jwksUri);
            
            if ($jwksResponse->failed()) {
                throw LogtoException::apiError('Failed to fetch JWKS: ' . $jwksResponse->body());
            }
            
            return $jwksResponse->json();
        });
    }

    /**
     * Validate ID token.
     */
    public function validateIdToken(string $idToken, ?string $nonce = null): array
    {
        try {
            $jwks = $this->getJwks();
            $keys = [];
            
            foreach ($jwks['keys'] as $key) {
                // Keep all key data, we'll handle different key types in buildPublicKey
                $keys[] = [
                    'kty' => $key['kty'],
                    'use' => $key['use'],
                    'kid' => $key['kid'],
                    'alg' => $key['alg'],
                    'n' => $key['n'] ?? null,
                    'e' => $key['e'] ?? null,
                    'x' => $key['x'] ?? null,
                    'y' => $key['y'] ?? null,
                    'crv' => $key['crv'] ?? null,
                ];
            }
            
            // Decode without verification first to get the kid
            $unverified = JWT::decode($idToken, new Key('', 'none'));
            $matchingKey = array_find($keys, fn($key) => $key['kid'] === ($unverified->kid ?? ''));
            
            if (!$matchingKey) {
                throw LogtoException::invalidToken('No matching key found for ID token');
            }
            
            // Build the key for verification based on key type
            $publicKey = $this->buildPublicKey($matchingKey);
            
            // Decode and verify
            $decoded = JWT::decode($idToken, new Key($publicKey, $matchingKey['alg']));
            
            // Convert to array
            $claims = (array) $decoded;
            
            // Validate nonce
            if ($nonce && ($claims['nonce'] ?? '') !== $nonce) {
                throw LogtoException::invalidToken('Invalid nonce in ID token');
            }
            
            // Validate issuer
            $issuer = $config['issuer'] ?? $this->oidcConfig['issuer'] ?? $this->endpoint;
            if (($claims['iss'] ?? '') !== $issuer) {
                throw LogtoException::invalidToken('Invalid issuer in ID token');
            }
            
            // Validate audience
            if (($claims['aud'] ?? '') !== $this->appId) {
                throw LogtoException::invalidToken('Invalid audience in ID token');
            }
            
            // Validate expiration
            if (isset($claims['exp']) && $claims['exp'] < time()) {
                throw LogtoException::invalidToken('ID token has expired');
            }
            
            return $claims;
        } catch (\Exception $e) {
            if (config('logto.logging.enabled')) {
                Log::error('ID token validation failed: ' . $e->getMessage());
            }
            throw LogtoException::invalidToken($e->getMessage());
        }
    }

    /**
     * Build public key from JWK based on key type.
     */
    protected function buildPublicKey(array $jwk): string
    {
        if ($jwk['kty'] === 'EC') {
            return $this->buildEcPublicKey($jwk);
        }
        
        // Default to RSA
        return $this->buildRsaPublicKey($jwk);
    }

    /**
     * Build RSA public key from JWK.
     */
    protected function buildRsaPublicKey(array $jwk): string
    {
        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            throw LogtoException::invalidToken('RSA key is missing required fields n or e');
        }
        
        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);
        
        $binaryExponent = pack('C*', ...array_values(unpack('C*', $exponent)));
        $binaryModulus = pack('C*', ...array_values(unpack('C*', $modulus)));
        
        $components = [
            1 => $binaryModulus,
            2 => $binaryExponent,
        ];
        
        $binaryDer = $this->encodeDer($components);
        
        return "-----BEGIN PUBLIC KEY-----\n" . \base64_encode($binaryDer) . "\n-----END PUBLIC KEY-----";
    }

    /**
     * Build EC public key from JWK (for ES256, ES384, ES512).
     */
    protected function buildEcPublicKey(array $jwk): string
    {
        if (!isset($jwk['x']) || !isset($jwk['y']) || !isset($jwk['crv'])) {
            throw LogtoException::invalidToken('EC key is missing required fields x, y, or crv');
        }
        
        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);
        $crv = $jwk['crv'];
        
        // Get OID for the curve
        $oid = null;
        if ($crv === 'P-256') {
            $oid = '1.2.840.10045.3.1.7'; // prime256v1 / secp256r1
        } elseif ($crv === 'P-384') {
            $oid = '1.3.132.0.34'; // secp384r1
        } elseif ($crv === 'P-521') {
            $oid = '1.3.132.0.35'; // secp521r1
        }
        
        if (!$oid) {
            throw LogtoException::invalidToken('Unsupported EC curve: ' . $crv);
        }
        
        // Build DER-encoded EC public key
        // Structure: SEQUENCE (OID, BIT STRING)
        $oidBytes = $this->encodeOidToDer($oid);
        $publicKeyBytes = \chr(0x04) . $x . $y; // Uncompressed point format
        $bitString = \chr(0x00) . $publicKeyBytes; // BIT STRING with 0 padding bits
        
        $innerSequence = $this->encodeDerSequenceBytes([$oidBytes, $bitString]);
        
        return "-----BEGIN PUBLIC KEY-----\n" . \base64_encode($innerSequence) . "\n-----END PUBLIC KEY-----";
    }

    /**
     * Encode an OID (Object Identifier) to DER bytes.
     */
    protected function encodeOidToDer(string $oid): string
    {
        $components = array_map('intval', explode('.', $oid));
        $first = $components[0];
        $second = $components[1];
        
        // First two components: first * 40 + second
        $firstByte = $first * 40 + $second;
        
        $bytes = [$firstByte];
        
        // Remaining components
        for ($i = 2; $i < count($components); $i++) {
            $component = $components[$i];
            $parts = [];
            
            while ($component >= 0) {
                $parts[] = $component & 0x7F;
                $component = (int)($component >> 7);
            }
            
            // Reverse parts and set high bit on all but last
            $encoded = '';
            for ($j = count($parts) - 1; $j >= 0; $j--) {
                $byte = $parts[$j];
                if ($j > 0) {
                    $byte |= 0x80;
                }
                $encoded .= \chr($byte);
            }
            
            $bytes = array_merge($bytes, str_split($encoded));
        }
        
        return implode('', $bytes);
    }

    /**
     * Encode a sequence of byte strings to DER.
     */
    protected function encodeDerSequenceBytes(array $byteStrings): string
    {
        $content = implode('', $byteStrings);
        return $this->encodeDerTagLengthValue(0x30, $content);
    }

    /**
     * Encode DER tag, length, and value.
     */
    protected function encodeDerTagLengthValue(int $tag, string $content): string
    {
        if (strlen($content) < 128) {
            return \chr($tag) . \chr(strlen($content)) . $content;
        }
        
        $lengthBytes = '';
        $length = strlen($content);
        while ($length > 0) {
            $lengthBytes = \chr($length & 0xFF) . $lengthBytes;
            $length = (int)($length >> 8);
        }
        $lengthBytes = \chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
        
        return \chr($tag) . $lengthBytes . $content;
    }

    /**
     * Base64 URL encode.
     */
    protected function base64UrlEncode(string $input): string
    {
        return strtr(rtrim(base64_encode($input), '='), '+/', '-_');
    }

    /**
     * Base64 URL decode.
     */
    protected function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        
        return base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Encode DER format.
     */
    protected function encodeDer(array $components): string
    {
        $bitString = '';
        
        foreach ($components as $key => $value) {
            $bitString .= $this->encodeInteger($key) . $this->encodeInteger(strlen((string) $value)) . $value;
        }
        
        return $this->encodeInteger(0x30) . $this->encodeInteger(strlen($bitString)) . $bitString;
    }

    /**
     * Encode integer for DER.
     */
    protected function encodeInteger(int $value): string
    {
        if ($value < 128) {
            return chr($value);
        }
        
        $bytes = '';
        while ($value > 0) {
            $bytes = chr($value & 0xff) . $bytes;
            $value = (int) ($value / 256);
        }
        
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Generate PKCE code challenge.
     */
    protected function generateCodeChallenge(string $codeVerifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * Logout user from Logto.
     * Uses the official SDK if available, otherwise falls back to manual URL generation.
     */
    public function logout(?string $idToken = null, ?string $postLogoutRedirectUri = null): string
    {
        // Try to use SDK adapter first
        if ($this->sdkAdapter) {
            try {
                return $this->sdkAdapter->getSignOutUrl($postLogoutRedirectUri, $idToken);
            } catch (\Exception $e) {
                Log::warning('SDK-based logout URL generation failed, falling back to manual: ' . $e->getMessage());
            }
        }

        // Fallback to manual URL generation
        return $this->logoutLegacy($idToken, $postLogoutRedirectUri);
    }

    /**
     * Legacy method for logout (manual URL generation).
     */
    protected function logoutLegacy(?string $idToken = null, ?string $postLogoutRedirectUri = null): string
    {
        $config = $this->getOidcConfig();
        
        $endSessionEndpoint = $config['end_session_endpoint'] 
            ?? $this->endpoint . '/oidc/session/end';
        
        $postLogoutRedirectUri ??= $this->oidcConfig['post_logout_redirect_uri'] 
        ?? url('/');
        
        $query = [
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'client_id' => $this->appId,
        ];
        
        if ($idToken) {
            $query['id_token_hint'] = $idToken;
        }
        
        return $endSessionEndpoint . '?' . http_build_query($query);
    }

    /**
     * Get access token for API requests (for current user).
     */
    public function getAccessToken(string $userId): ?string
    {
        return $this->tokenManager->getAccessToken($userId);
    }

    /**
     * Make authenticated API request.
     */
    public function request(string $method, string $path, array $options = [], ?string $userId = null): array
    {
        try {
            $token = $userId ? $this->tokenManager->getAccessToken($userId) : null;
            
            $headers = ['Accept' => 'application/json'];
            
            if ($token) {
                $headers['Authorization'] = 'Bearer ' . $token;
            } elseif ($this->appSecret) {
                $headers['Authorization'] = 'Basic ' . base64_encode($this->appId . ':' . $this->appSecret);
            }
            
            $response = $this->httpClient->request($method, $path, array_merge($options, [
                'headers' => $headers,
            ]));
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'API request failed: ' . $response->body(),
                    $response->status()
                );
            }
            
            return $response->json() ?? [];
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Get user by access token.
     */
    public function getUserByAccessToken(string $accessToken): array
    {
        $userInfo = $this->getUserInfo($accessToken);
        
        // Validate ID token if present
        if (isset($userInfo['id_token'])) {
            $idTokenClaims = $this->validateIdToken($userInfo['id_token']);
            $userInfo['id_token_claims'] = $idTokenClaims;
        }
        
        return $userInfo;
    }

    /**
     * Exchange client credentials for tokens (for machine-to-machine).
     */
    public function clientCredentialsFlow(array $scopes = []): array
    {
        try {
            $config = $this->getOidcConfig();
            
            // Extract relative path from token endpoint URL
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? '/oidc/token';
            
            // Remove base URI from endpoint if present (to avoid double URLs)
            $tokenEndpoint = $this->stripBaseUrl($tokenEndpoint);
            
            $scopeString = $scopes ? implode(' ', $scopes) : '';
            
            // Use Basic Auth for client authentication (required by Logto)
            // client_id and client_secret are sent via Authorization header
            $response = $this->httpClient->withBasicAuth($this->appId, $this->appSecret)
                ->post($tokenEndpoint, [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'scope' => $scopeString,
                    ],
                ]);
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'Client credentials flow failed: ' . $response->body(),
                    $response->status()
                );
            }
            
            return $response->json();
        } catch (ConnectionException $e) {
            throw LogtoException::networkError($e->getMessage());
        }
    }

    /**
     * Strip base URL from a full URL to get relative path.
     * This prevents double URLs when using HTTP client with base_uri.
     */
    protected function stripBaseUrl(string $url): string
    {
        // If URL starts with http:// or https://, extract the path
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parsed = parse_url($url);
            return $parsed['path'] ?? '/';
        }
        
        // If URL starts with /, it's already relative
        if (str_starts_with($url, '/')) {
            return $url;
        }
        
        // Otherwise, prepend /
        return '/' . ltrim($url, '/');
    }
}
