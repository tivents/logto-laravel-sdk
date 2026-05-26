<?php

namespace TIVENTS\LogtoLaravelSdk\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string|null  $redirectToRoute
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $guardName = config('logto.guard.name', 'logto');
        
        if (!$this->shouldVerifyEmail($guardName)) {
            return $next($request);
        }

        $user = Auth::guard($guardName)->user();

        if ($user && $this->hasVerifiedEmail($user)) {
            return $next($request);
        }

        return $this->redirectToVerifiedRoute($request, $redirectToRoute);
    }

    /**
     * Determine if email verification is required for the guard.
     */
    protected function shouldVerifyEmail(string $guardName): bool
    {
        return config('logto.user.auto_update', true) 
            && config('logto.oidc.scopes', []) === ['openid', 'profile', 'email'];
    }

    /**
     * Check if the user has verified their email.
     */
    protected function hasVerifiedEmail($user): bool
    {
        // Check if user has verified email from Logto
        $userInfo = Auth::guard(config('logto.guard.name', 'logto'))->getUserInfo();
        
        if ($userInfo && !empty($userInfo['email_verified'])) {
            return true;
        }

        // Fallback to Laravel's email_verified_at
        return $user->hasVerifiedEmail();
    }

    /**
     * Redirect to the appropriate route for email verification.
     */
    protected function redirectToVerifiedRoute(Request $request, ?string $redirectToRoute): RedirectResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'message' => 'Email not verified.',
                'redirect' => $this->getRedirectUrl($redirectToRoute),
            ], 403);
        }

        return Redirect::to($this->getRedirectUrl($redirectToRoute));
    }

    /**
     * Get the redirect URL for unverified users.
     */
    protected function getRedirectUrl(?string $redirectToRoute): string
    {
        if ($redirectToRoute) {
            return route($redirectToRoute);
        }

        if (config('logto.middleware.ensure_email_verified')) {
            return route(config('logto.middleware.ensure_email_verified'));
        }

        return url('/email/verify');
    }
}
