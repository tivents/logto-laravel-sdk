<?php

namespace TIVENTS\LogtoLaravelSdk\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

class AuthenticateWithLogto
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string[]  ...$guards
     * @return mixed
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function handle(Request $request, Closure $next, ...$guards): SymfonyResponse
    {
        $guardName = $guards[0] ?? config('logto.guard.name', 'logto');
        
        if (Auth::guard($guardName)->check()) {
            // User is authenticated, proceed with request
            // Optionally refresh token if it's about to expire
            $this->maybeRefreshToken($request, $guardName);
            
            return $next($request);
        }

        // User is not authenticated
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'redirect' => $this->getLoginUrl($request),
            ], 401);
        }

        // Redirect to login
        return redirect()->guest($this->getLoginUrl($request));
    }

    /**
     * Get the login URL for the guard.
     */
    protected function getLoginUrl(Request $request): string
    {
        $guard = config('logto.guard.name', 'logto');
        
        // Get authorization URL from Logto client
        try {
            $client = app('logto');
            $authUrl = $client->getAuthorizationUrl();
            
            // Store intended URL in session
            session()->put('url.intended', url()->current());
            
            return $authUrl;
        } catch (\Exception) {
            // Fallback to default login route
            return route('login', ['guard' => $guard]);
        }
    }

    /**
     * Refresh token if it's about to expire.
     */
    protected function maybeRefreshToken(Request $request, string $guardName): void
    {
        try {
            $guard = Auth::guard($guardName);
            $user = $guard->user();
            
            if (!$user) {
                return;
            }
            
            $tokenManager = app(\TIVENTS\LogtoLaravelSdk\Services\TokenManager::class);
            $expiration = $tokenManager->getTokenExpiration($user->getAuthIdentifier());
            
            // Refresh if token expires within the next 5 minutes
            if ($expiration && ($expiration - time()) < 300) {
                $guard->refreshToken();
            }
        } catch (\Exception) {
            // Silently fail - token refresh can be handled later
        }
    }
}
