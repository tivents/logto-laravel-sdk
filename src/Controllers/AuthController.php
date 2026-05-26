<?php

namespace TIVENTS\LogtoLaravelSdk\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;

class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        /**
         * The Logto client instance.
         */
        protected LogtoClient $client
    )
    {
    }

    /**
     * Handle the OIDC callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            // Validate required parameters
            if (!$request->has('code')) {
                throw LogtoException::authenticationFailed('Authorization code is required');
            }
            
            $code = $request->get('code');
            $state = $request->get('state');
            $error = $request->get('error');
            
            // Check for OAuth errors
            if ($error) {
                $errorDescription = $request->get('error_description', 'Unknown error');
                throw LogtoException::authenticationFailed(
                    'OAuth error: ' . $error . ' - ' . $errorDescription
                );
            }
            
            // Validate state parameter (CSRF protection)
            if (!$state || $state !== Session::pull('logto_state')) {
                throw LogtoException::authenticationFailed('Invalid state parameter');
            }
            
            // Get nonce
            $nonce = Session::pull('logto_nonce');
            
            // Exchange code for tokens
            $tokens = $this->client->exchangeCodeForTokens($code);
            
            // Validate ID token if present
            if (isset($tokens['id_token'])) {
                $this->client->validateIdToken($tokens['id_token'], $nonce);
            }
            
            // Get user information
            $userInfo = $this->client->getUserInfo($tokens['access_token']);
            
            // Store tokens in session for the guard to process
            Session::put('logto_tokens', $tokens);
            Session::put('logto_user_info', $userInfo);
            
            // Get the guard and handle authentication
            $guardName = config('logto.guard.name', 'logto');
            $guard = Auth::guard($guardName);
            
            // Use the guard to handle user creation/lookup
            $user = $guard->handleCallback($request);
            
            if (!$user) {
                throw LogtoException::authenticationFailed('Failed to authenticate user');
            }
            
            // Regenerate session to prevent fixation attacks
            $request->session()->regenerate();
            
            // Redirect to intended URL or home
            $intendedUrl = Session::pull('url.intended');
            
            return Redirect::to($intendedUrl ?: config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('success', 'Successfully authenticated with Logto');
            
        } catch (LogtoException $e) {
            // Log the error
            \Log::error('Logto authentication failed: ' . $e->getMessage());
            
            return Redirect::to(config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('error', 'Authentication failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Log unexpected errors
            \Log::error('Unexpected error during Logto authentication: ' . $e->getMessage());
            
            return Redirect::to(config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('error', 'An unexpected error occurred during authentication');
        }
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request): RedirectResponse
    {
        $guardName = config('logto.guard.name', 'logto');
        $guard = Auth::guard($guardName);
        
        // Get current user info for logout hint
        $userInfo = $guard->getUserInfo();
        $idToken = $userInfo['id_token'] ?? null;
        
        // Logout from Laravel
        $guard->logout();
        
        // Clear session
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        // Generate logout URL
        $postLogoutRedirectUri = config('logto.oidc.post_logout_redirect_uri', '/');
        $logoutUrl = $this->client->logout($idToken, $postLogoutRedirectUri);
        
        // Redirect to Logto logout
        return Redirect::to($logoutUrl)
            ->with('success', 'Successfully logged out');
    }

    /**
     * Redirect to Logto for authentication.
     */
    public function redirectToLogto(Request $request): RedirectResponse
    {
        $guardName = config('logto.guard.name', 'logto');
        
        // Store intended URL
        if ($request->has('redirect_uri')) {
            Session::put('url.intended', $request->get('redirect_uri'));
        }
        
        // Generate authorization URL
        try {
            $authUrl = $this->client->getAuthorizationUrl();
            
            return Redirect::to($authUrl);
        } catch (LogtoException $e) {
            return Redirect::to(config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('error', 'Failed to initiate authentication: ' . $e->getMessage());
        }
    }

    /**
     * Get user information (for API endpoints).
     */
    public function userInfo(Request $request): array
    {
        $guardName = config('logto.guard.name', 'logto');
        $guard = Auth::guard($guardName);
        
        if (!$guard->check()) {
            return [
                'authenticated' => false,
                'error' => 'Unauthenticated',
            ];
        }
        
        $userInfo = $guard->getUserInfo();
        
        return [
            'authenticated' => true,
            'user' => $userInfo,
            'access_token' => $guard->getAccessToken(),
            'token_expires_at' => $guard->getTokenManager()->getTokenExpiration(
                $guard->id()
            ),
        ];
    }

    /**
     * Refresh access token (for API endpoints).
     */
    public function refreshToken(Request $request): array
    {
        $guardName = config('logto.guard.name', 'logto');
        $guard = Auth::guard($guardName);
        
        if (!$guard->check()) {
            return [
                'error' => 'Unauthenticated',
            ];
        }
        
        try {
            $tokens = $guard->refreshToken();
            
            return [
                'access_token' => $tokens['access_token'] ?? null,
                'expires_in' => $tokens['expires_in'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_type' => $tokens['token_type'] ?? 'Bearer',
            ];
        } catch (LogtoException $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
