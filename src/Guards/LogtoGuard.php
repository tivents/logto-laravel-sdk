<?php

namespace TIVENTS\LogtoLaravelSdk\Guards;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

class LogtoGuard implements Guard
{
    use GuardHelpers;

    /**
     * Session store.
     */
    protected ?Store $session = null;

    /**
     * The name of the guard.
     */
    protected string $name = 'logto';

    /**
     * The currently authenticated user.
     */
    protected $user;

    /**
     * Create a new guard instance.
     */
    public function __construct(
        /**
         * The Logto client instance.
         */
        protected LogtoClient $client,
        /**
         * The token manager instance.
         */
        protected TokenManager $tokenManager,
        /**
         * The request instance.
         */
        protected Request $request,
        /**
         * The fallback guard for user resolution.
         */
        protected ?Guard $fallbackGuard = null
    ) {
        $this->session = Session::driver();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        // Try to get user from session
        if ($this->session && $this->session->has('logto_user_id')) {
            $userId = $this->session->get('logto_user_id');
            
            if ($this->fallbackGuard && $user = $this->fallbackGuard->getUser()) {
                $this->user = $user;
                return $user;
            }
            
            // Try to get user by ID
            if ($this->provider && $user = $this->provider->retrieveById($userId)) {
                $this->user = $user;
                return $user;
            }
        }

        // Check for valid tokens
        if ($this->session && $this->session->has('logto_user_id')) {
            $userId = $this->session->get('logto_user_id');
            
            if ($this->tokenManager->hasValidTokens($userId)) {
                // Try to refresh user info
                $accessToken = $this->tokenManager->getAccessToken($userId);
                if ($accessToken) {
                    try {
                        $userInfo = $this->client->getUserByAccessToken($accessToken);
                        
                        if ($this->fallbackGuard) {
                            $this->user = $this->fallbackGuard->getUser();
                            return $this->user;
                        }
                        
                        // Find or create user
                        $user = $this->findOrCreateUser($userInfo);
                        if ($user) {
                            $this->user = $user;
                            return $user;
                        }
                    } catch (LogtoException) {
                        // Token might be expired, clear and return null
                        $this->tokenManager->clearTokens($userId);
                        $this->session->forget(['logto_user_id', 'logto_tokens']);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): ?string
    {
        if ($user = $this->user()) {
            return $user->getAuthIdentifier();
        }
        
        return $this->session ? $this->session->get('logto_user_id') : null;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        // For OAuth, validation happens through the callback
        // This method is mainly for username/password validation
        return false;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        
        if ($this->session) {
            $this->session->put('logto_user_id', $user->getAuthIdentifier());
        }
        
        return $this;
    }

    /**
     * Attempt to authenticate a user with the given credentials.
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        // For OAuth, we don't use traditional credentials
        // This is for compatibility with Laravel's auth system
        return false;
    }

    /**
     * Log a user into the application without sessions or cookies.
     */
    public function once(array $credentials = []): ?Authenticatable
    {
        return null;
    }

    /**
     * Login a user manually.
     */
    public function login(Authenticatable $user, $remember = false): void
    {
        $this->user = $user;
        
        if ($this->session) {
            $this->session->put('logto_user_id', $user->getAuthIdentifier());
            $this->session->put('logto_authenticated', true);
            $this->session->put('logto_authenticated_at', time());
        }
    }

    /**
     * Log the user out of the application.
     */
    public function logout(): void
    {
        $userId = $this->id();
        
        if ($userId) {
            $this->tokenManager->clearTokens($userId);
        }
        
        $this->user = null;
        
        if ($this->session) {
            $this->session->forget([
                'logto_user_id',
                'logto_tokens',
                'logto_authenticated',
                'logto_authenticated_at',
                'logto_state',
                'logto_nonce',
                'logto_code_verifier',
            ]);
        }
        
        if ($this->fallbackGuard) {
            $this->fallbackGuard->logout();
        }
    }

    /**
     * Get the current request.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get the Logto client.
     */
    public function getClient(): LogtoClient
    {
        return $this->client;
    }

    /**
     * Get the token manager.
     */
    public function getTokenManager(): TokenManager
    {
        return $this->tokenManager;
    }

    /**
     * Handle OAuth callback and authenticate user.
     */
    public function handleCallback(Request $request): ?Authenticatable
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');
        
        // Check for errors
        if ($error) {
            throw LogtoException::authenticationFailed(
                'OAuth error: ' . ($request->get('error_description') ?? $error)
            );
        }
        
        // Validate state
        if (!$state || $state !== $this->session->pull('logto_state')) {
            throw LogtoException::authenticationFailed('Invalid state parameter');
        }
        
        // Validate nonce
        $nonce = $this->session->pull('logto_nonce');
        
        // Exchange code for tokens
        $tokens = $this->client->exchangeCodeForTokens($code);
        
        // Get user info
        $userInfo = $this->client->getUserInfo($tokens['access_token']);
        
        // Validate ID token if present
        if (isset($tokens['id_token'])) {
            $idTokenClaims = $this->client->validateIdToken($tokens['id_token'], $nonce);
            $userInfo = array_merge($userInfo, ['id_token_claims' => $idTokenClaims]);
        }
        
        // Store tokens
        $logtoUserId = $userInfo['sub'] ?? $userInfo['id'] ?? uniqid();
        $this->tokenManager->storeTokens($logtoUserId, $tokens);
        
        // Find or create user
        $user = $this->findOrCreateUser($userInfo);
        
        if ($user) {
            // Store user ID in session
            $this->session->put('logto_user_id', $user->getAuthIdentifier());
            $this->session->put('logto_tokens', $tokens);
            $this->session->put('logto_authenticated', true);
            $this->session->put('logto_authenticated_at', time());
            $this->session->put('logto_user_info', $userInfo);
            
            $this->user = $user;
        }
        
        return $user;
    }

    /**
     * Find or create user based on Logto user info.
     */
    protected function findOrCreateUser(array $userInfo): ?Authenticatable
    {
        if (!$this->provider) {
            return null;
        }
        
        $userMapping = config('logto.user.mapping', []);
        $autoCreate = config('logto.user.auto_create', true);
        $autoUpdate = config('logto.user.auto_update', true);
        $defaultRole = config('logto.user.default_role', 'user');
        
        // Get the Logto user ID
        $logtoId = $userInfo['sub'] ?? $userInfo['id'] ?? null;
        
        if (!$logtoId) {
            throw LogtoException::authenticationFailed('No user ID in user info');
        }
        
        // Try to find existing user by Logto ID
        if ($this->fallbackGuard) {
            // Use the fallback guard's provider
            $user = $this->fallbackGuard->getProvider()->retrieveByCredentials([
                'logto_id' => $logtoId,
            ]);
            
            if ($user) {
                // Update user info if auto_update is enabled
                if ($autoUpdate) {
                    $this->updateUserFromLogto($user, $userInfo, $userMapping);
                }
                return $user;
            }
            
            // Create new user if auto_create is enabled
            if ($autoCreate) {
                return $this->createUserFromLogto($userInfo, $userMapping, $defaultRole);
            }
        }
        
        return null;
    }

    /**
     * Create a new user from Logto user info.
     */
    protected function createUserFromLogto(array $userInfo, array $mapping, string $defaultRole): ?Authenticatable
    {
        if (!$this->provider) {
            return null;
        }
        
        $userModel = config('logto.user.model', \App\Models\User::class);
        
        $userData = [
            'logto_id' => $userInfo['sub'] ?? $userInfo['id'],
            'name' => $userInfo[$mapping['name'] ?? 'name'] ?? '',
            'email' => $userInfo[$mapping['email'] ?? 'email'] ?? '',
            'email_verified_at' => !empty($userInfo[$mapping['email_verified'] ?? 'email_verified'])
                ? now()
                : null,
            'password' => bcrypt(Str::random(32)), // Random password
            'remember_token' => Str::random(10),
        ];
        
        // Add optional fields if they exist
        if (isset($mapping['picture']) && !empty($userInfo[$mapping['picture']])) {
            $userData['avatar'] = $userInfo[$mapping['picture']];
        }
        
        if (isset($mapping['phone']) && !empty($userInfo[$mapping['phone']])) {
            $userData['phone'] = $userInfo[$mapping['phone']];
        }
        
        // Create the user
        $user = new $userModel($userData);
        $user->save();
        
        // Assign role if using Spatie permissions
        if (class_exists('\Spatie\Permission\PermissionServiceProvider')) {
            $user->assignRole($defaultRole);
        }
        
        return $user;
    }

    /**
     * Update existing user with latest info from Logto.
     */
    protected function updateUserFromLogto(Authenticatable $user, array $userInfo, array $mapping): void
    {
        $updateData = [];
        
        // Update name if changed
        if (isset($mapping['name']) && !empty($userInfo[$mapping['name']])) {
            $updateData['name'] = $userInfo[$mapping['name']];
        }
        
        // Update email if changed
        if (isset($mapping['email']) && !empty($userInfo[$mapping['email']])) {
            $updateData['email'] = $userInfo[$mapping['email']];
            $updateData['email_verified_at'] = !empty($userInfo[$mapping['email_verified'] ?? 'email_verified'])
                ? now()
                : null;
        }
        
        // Update avatar if changed
        if (isset($mapping['picture']) && !empty($userInfo[$mapping['picture']])) {
            $updateData['avatar'] = $userInfo[$mapping['picture']];
        }
        
        // Update phone if changed
        if (isset($mapping['phone']) && !empty($userInfo[$mapping['phone']])) {
            $updateData['phone'] = $userInfo[$mapping['phone']];
        }
        
        if (!empty($updateData)) {
            $user->update($updateData);
        }
    }

    /**
     * Check if the user is authenticated.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Check if the user is a guest.
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user or throw an exception.
     */
    public function authenticate(): Authenticatable
    {
        $user = $this->user();
        
        if (!$user) {
            throw LogtoException::authenticationFailed('Unauthenticated.');
        }
        
        return $user;
    }

    /**
     * Get the user's access token.
     */
    public function getAccessToken(): ?string
    {
        if ($user = $this->user()) {
            return $this->tokenManager->getAccessToken($user->getAuthIdentifier());
        }
        
        return null;
    }

    /**
     * Refresh the access token for the current user.
     */
    public function refreshToken(): array
    {
        if ($user = $this->user()) {
            return $this->tokenManager->refreshAccessToken(
                $user->getAuthIdentifier(),
                $this->client
            );
        }
        
        throw LogtoException::authenticationFailed('Cannot refresh token: User not authenticated');
    }

    /**
     * Get the user info from the last authentication.
     */
    public function getUserInfo(): ?array
    {
        return $this->session ? $this->session->get('logto_user_info') : null;
    }
}
