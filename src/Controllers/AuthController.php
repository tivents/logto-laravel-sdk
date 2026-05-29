<?php

namespace TIVENTS\LogtoLaravelSdk\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\LogtoSdkAdapter;

class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        /**
         * The Logto client instance.
         */
        protected LogtoClient $client,
        /**
         * The Logto SDK adapter instance.
         */
        protected LogtoSdkAdapter $sdkAdapter
    )
    {
    }

    /**
     * Handle the OIDC callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $redirectUri = url(config('logto.oidc.redirect_uri', '/auth/logto/callback'));
            
            // Check for OAuth errors first
            $error = $request->get('error');
            if ($error) {
                $errorDescription = $request->get('error_description', 'Unknown error');
                throw LogtoException::authenticationFailed(
                    'OAuth error: ' . $error . ' - ' . $errorDescription
                );
            }
            
            // Validate required parameters
            if (!$request->has('code')) {
                // If no authorization code, redirect to Logto login with helpful message
                try {
                    $loginUrl = $this->client->getAuthorizationUrl();
                    return Redirect::to($loginUrl)
                        ->with('error', 'Please authenticate through Logto first.');
                } catch (\Exception $e) {
                    return Redirect::to('/')
                        ->with('error', 'Logto authentication not configured. Please check your Logto settings.');
                }
            }
            
            // Use the client to handle the callback (consistent with redirectToLogto)
            // This will exchange code for tokens and get user info
            $code = $request->get('code');
            $tokens = $this->client->exchangeCodeForTokens($code);
            $userInfo = $this->client->getUserInfo($tokens['access_token']);
            
            $result = ['tokens' => $tokens, 'user_info' => $userInfo];
            
            // Validate ID token if present (using the client's method)
            if (isset($tokens['id_token'])) {
                $nonce = Session::pull('logto_nonce');
                try {
                    $this->client->validateIdToken($tokens['id_token'], $nonce);
                } catch (\Exception $e) {
                    Log::warning('ID token validation failed: ' . $e->getMessage());
                }
            }
            
            // Store tokens in session for the guard to process
            Session::put('logto_tokens', $tokens);
            Session::put('logto_user_info', $userInfo);
            
            // Sync tokens with TokenManager
            if (isset($tokens['access_token'])) {
                $this->client->getTokenManager()->storeTokens(
                    'sdk_user', // Will be updated with actual user ID by guard
                    $tokens
                );
            }
            
            // Get the guard and handle authentication
            $guardName = config('logto.guard.name', 'logto');
            $guard = Auth::guard($guardName);
            
            // Use the guard to handle user creation/lookup
            $user = $guard->handleCallback($request);
            
            if (!$user) {
                throw LogtoException::authenticationFailed('Failed to authenticate user');
            }
            
            // Update tokens with the actual user ID
            if (isset($tokens['access_token']) && $user) {
                $this->client->getTokenManager()->storeTokens(
                    $user->getAuthIdentifier(),
                    $tokens
                );
            }
            
            // Regenerate session to prevent fixation attacks
            $request->session()->regenerate();
            
            // Redirect to intended URL or home
            $intendedUrl = Session::pull('url.intended');
            
            return Redirect::to($intendedUrl ?: config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('success', 'Successfully authenticated with Logto');
            
        } catch (LogtoException $e) {
            // Log the error
            Log::error('Logto authentication failed: ' . $e->getMessage());
            
            return Redirect::to(config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('error', 'Authentication failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Unexpected error during Logto authentication: ' . $e->getMessage());
            
            return Redirect::to(config('logto.oidc.post_logout_redirect_uri', '/'))
                ->with('error', 'An unexpected error occurred during authentication');
        }
    }

    /**
     * Handle user logout.
     * Uses manual logout URL generation to avoid SDK memory issues.
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
        
        // Generate logout URL using manual method (avoid SDK memory issues)
        try {
            $postLogoutRedirectUri = config('logto.oidc.post_logout_redirect_uri', '/');
            $logoutUrl = $this->client->logout($idToken, $postLogoutRedirectUri);
            
            return Redirect::to($logoutUrl)
                ->with('success', 'Successfully logged out');
        } catch (LogtoException $e) {
            return Redirect::to($postLogoutRedirectUri ?? '/')
                ->with('success', 'Successfully logged out');
        }
    }

    /**
     * Redirect to Logto for authentication.
     * Uses manual URL generation to avoid SDK memory issues during initial request.
     * The SDK is used in the callback where it's more appropriate.
     */
    public function redirectToLogto(Request $request): RedirectResponse
    {
        $guardName = config('logto.guard.name', 'logto');
        
        // Store intended URL
        if ($request->has('redirect_uri')) {
            Session::put('url.intended', $request->get('redirect_uri'));
        }
        
        // Generate authorization URL
        // (SDK is used in callback where session is already established)
        try {
            $redirectUri = url(config('logto.oidc.redirect_uri', '/auth/logto/callback'));
            $authUrl = $this->client->getAuthorizationUrl(null, null, $redirectUri);
            
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
