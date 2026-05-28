<?php

namespace TIVENTS\LogtoLaravelSdk\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

class LogtoClient
{
    protected PendingRequest $httpClient;
    
    protected string $appId;
    
    protected string $appSecret;
    
    protected string $endpoint;
    
    protected array $oidcConfig;

    public function __construct(protected TokenManager $tokenManager)
    {
        $this->appId = config('logto.app_id');
        $this->appSecret = config('logto.app_secret');
        $this->endpoint = rtrim((string) config('logto.endpoint'), '/');
        $this->oidcConfig = config('logto.oidc', []);
        
        $this->httpClient = Http::withOptions([
            'base_uri' => $this->endpoint,
            'timeout' => config('logto.http.timeout', 30),
            'connect_timeout' => config('logto.http.connect_timeout', 10),
            'verify' => config('logto.http.verify', true),
        ]);
    }

    /**
     * Discover OIDC configuration from Logto.
     */
    public function discoverOidcConfig(): array
    {
        try {
            if (!empty($this->oidcConfig['discovery_endpoint'])) {
                $url = $this->oidcConfig['discovery_endpoint'];
            } else {
                $url = $this->endpoint . '/oidc/.well-known/openid-configuration';
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
     */
    public function getAuthorizationUrl(?string $state = null, ?string $nonce = null): string
    {
        $config = $this->getOidcConfig();
        
        $authorizationEndpoint = $config['authorization_endpoint'] 
            ?? $this->oidcConfig['authorization_endpoint'] 
            ?? $this->endpoint . '/oidc/auth';
        
        $redirectUri = $this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback';
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
            'redirect_uri' => url($redirectUri),
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
     */
    public function exchangeCodeForTokens(string $code): array
    {
        try {
            $config = $this->getOidcConfig();
            
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? $this->endpoint . '/oidc/token';
            
            $redirectUri = $this->oidcConfig['redirect_uri'] ?? '/auth/logto/callback';
            
            $params = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => url($redirectUri),
            ];
            
            // Add PKCE code verifier
            if (session()->has('logto_code_verifier')) {
                $params['code_verifier'] = session()->pull('logto_code_verifier');
            }
            
            // Use Basic Auth for client authentication (OIDC best practice)
            // client_id and client_secret are sent via Authorization header, not in form params
            $response = $this->httpClient->withBasicAuth($this->appId, $this->appSecret)
                ->post($tokenEndpoint, [
                    'form_params' => $params,
                ]);
            
            if ($response->failed()) {
                throw LogtoException::apiError(
                    'Failed to exchange code for tokens: ' . $response->body(),
                    $response->status()
                );
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
            
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? $this->endpoint . '/oidc/token';
            
            // Use Basic Auth for client authentication (OIDC best practice)
            // client_id and client_secret are sent via Authorization header, not in form params
            $response = $this->httpClient->withBasicAuth($this->appId, $this->appSecret)
                ->post($tokenEndpoint, [
                    'form_params' => [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
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
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $config = $this->getOidcConfig();
            
            $userinfoEndpoint = $config['userinfo_endpoint'] 
                ?? $this->oidcConfig['userinfo_endpoint'] 
                ?? $this->endpoint . '/oidc/me';
            
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
     * Validate ID token.
     */
    public function validateIdToken(string $idToken, ?string $nonce = null): array
    {
        try {
            $config = $this->getOidcConfig();
            $jwksUri = $config['jwks_uri'] ?? $this->oidcConfig['jwks_uri'] ?? $this->endpoint . '/oidc/jwks';
            
            // Fetch JWKS
            $jwksResponse = $this->httpClient->get($jwksUri);
            
            if ($jwksResponse->failed()) {
                throw LogtoException::apiError('Failed to fetch JWKS');
            }
            
            $jwks = $jwksResponse->json();
            $keys = [];
            
            foreach ($jwks['keys'] as $key) {
                $keys[] = [
                    'kty' => $key['kty'],
                    'use' => $key['use'],
                    'kid' => $key['kid'],
                    'alg' => $key['alg'],
                    'n' => $key['n'],
                    'e' => $key['e'],
                ];
            }
            
            // Decode without verification first to get the kid
            $unverified = JWT::decode($idToken, new Key('', 'none'));
            $matchingKey = array_find($keys, fn($key) => $key['kid'] === ($unverified->kid ?? ''));
            
            if (!$matchingKey) {
                throw LogtoException::invalidToken('No matching key found for ID token');
            }
            
            // Build the key for verification
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
            if (Log::isEnabled()) {
                Log::error('ID token validation failed: ' . $e->getMessage());
            }
            throw LogtoException::invalidToken($e->getMessage());
        }
    }

    /**
     * Build public key from JWK.
     */
    protected function buildPublicKey(array $jwk): string
    {
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
     */
    public function logout(?string $idToken = null, ?string $postLogoutRedirectUri = null): string
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
            
            $tokenEndpoint = $config['token_endpoint'] 
                ?? $this->oidcConfig['token_endpoint'] 
                ?? $this->endpoint . '/oidc/token';
            
            $scopeString = $scopes ? implode(' ', $scopes) : '';
            
            // Use Basic Auth for client authentication (OIDC best practice)
            // client_id and client_secret are sent via Authorization header, not in form params
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
}
