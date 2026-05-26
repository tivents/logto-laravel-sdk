<?php

return [
    /**
     * Logto Application Configuration
     */
    'app_id' => env('LOGTO_APP_ID'),
    'app_secret' => env('LOGTO_APP_SECRET'),
    'endpoint' => env('LOGTO_ENDPOINT', 'https://your-logto-instance.logto.app'),

    /**
     * OIDC Configuration
     */
    'oidc' => [
        'enabled' => env('LOGTO_OIDC_ENABLED', true),
        'discovery_endpoint' => env('LOGTO_OIDC_DISCOVERY_ENDPOINT'),
        'authorization_endpoint' => env('LOGTO_OIDC_AUTHORIZATION_ENDPOINT'),
        'token_endpoint' => env('LOGTO_OIDC_TOKEN_ENDPOINT'),
        'userinfo_endpoint' => env('LOGTO_OIDC_USERINFO_ENDPOINT'),
        'jwks_uri' => env('LOGTO_OIDC_JWKS_URI'),
        'issuer' => env('LOGTO_OIDC_ISSUER'),
        
        /**
         * Redirect URIs for OAuth/OIDC flows
         */
        'redirect_uri' => env('LOGTO_OIDC_REDIRECT_URI', '/auth/logto/callback'),
        'post_logout_redirect_uri' => env('LOGTO_OIDC_POST_LOGOUT_REDIRECT_URI', '/'),
        
        /**
         * Scopes to request during authentication
         */
        'scopes' => explode(' ', env('LOGTO_OIDC_SCOPES', 'openid profile email')),
        
        /**
         * PKCE support for authorization code flow
         */
        'pkce' => env('LOGTO_OIDC_PKCE', true),
    ],

    /**
     * Session Configuration
     */
    'session' => [
        'driver' => env('LOGTO_SESSION_DRIVER', 'file'),
        'lifetime' => env('LOGTO_SESSION_LIFETIME', 120), // minutes
        'domain' => env('LOGTO_SESSION_DOMAIN', null),
        'secure' => env('LOGTO_SESSION_SECURE', false),
        'http_only' => env('LOGTO_SESSION_HTTP_ONLY', true),
        'same_site' => env('LOGTO_SESSION_SAME_SITE', 'lax'),
    ],

    /**
     * Token Configuration
     */
    'tokens' => [
        'access_token_lifetime' => env('LOGTO_ACCESS_TOKEN_LIFETIME', 3600), // seconds
        'refresh_token_lifetime' => env('LOGTO_REFRESH_TOKEN_LIFETIME', 86400), // seconds
        'store_encrypted' => env('LOGTO_STORE_ENCRYPTED', true),
    ],

    /**
     * User Configuration
     */
    'user' => [
        /**
         * Map Logto user claims to Laravel user attributes
         */
        'mapping' => [
            'id' => 'sub',
            'name' => 'name',
            'email' => 'email',
            'email_verified' => 'email_verified',
            'picture' => 'picture',
            'locale' => 'locale',
            'phone' => 'phone',
            'phone_verified' => 'phone_verified',
        ],
        
        /**
         * Default user model to use for authentication
         */
        'model' => env('LOGTO_USER_MODEL', \App\Models\User::class),
        
        /**
         * Whether to automatically create users on first login
         */
        'auto_create' => env('LOGTO_AUTO_CREATE_USERS', true),
        
        /**
         * Whether to automatically update user information on subsequent logins
         */
        'auto_update' => env('LOGTO_AUTO_UPDATE_USERS', true),
        
        /**
         * Default role to assign to new users
         */
        'default_role' => env('LOGTO_DEFAULT_ROLE', 'user'),
    ],

    /**
     * Guard Configuration
     */
    'guard' => [
        'name' => env('LOGTO_GUARD_NAME', 'logto'),
        'driver' => env('LOGTO_GUARD_DRIVER', 'session'),
        'provider' => env('LOGTO_GUARD_PROVIDER', 'users'),
    ],

    /**
     * Middleware Configuration
     */
    'middleware' => [
        'authenticate' => env('LOGTO_MIDDLEWARE_AUTHENTICATE', 'auth:logto'),
        'ensure_email_verified' => env('LOGTO_MIDDLEWARE_EMAIL_VERIFIED', 'verified'),
    ],

    /**
     * HTTP Client Configuration
     */
    'http' => [
        'timeout' => env('LOGTO_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('LOGTO_HTTP_CONNECT_TIMEOUT', 10),
        'verify' => env('LOGTO_HTTP_VERIFY_SSL', true),
        'proxy' => env('LOGTO_HTTP_PROXY'),
    ],

    /**
     * Logging Configuration
     */
    'logging' => [
        'enabled' => env('LOGTO_LOGGING_ENABLED', true),
        'level' => env('LOGTO_LOGGING_LEVEL', 'info'),
        'channel' => env('LOGTO_LOGGING_CHANNEL', 'stack'),
    ],

    /**
     * Admin API Configuration
     */
    'admin' => [
        'enabled' => env('LOGTO_ADMIN_ENABLED', false),
        'api_key' => env('LOGTO_ADMIN_API_KEY'),
        'api_endpoint' => env('LOGTO_ADMIN_API_ENDPOINT'),
    ],
];
