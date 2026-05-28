<?php

use Illuminate\Support\Facades\Config;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

beforeEach(function () {
    Config::set('logto.app_id', 'test-app-id');
    Config::set('logto.app_secret', 'test-app-secret');
    Config::set('logto.endpoint', 'https://test.logto.app');
    Config::set('logto.oidc.redirect_uri', '/auth/logto/callback');
    Config::set('logto.oidc.scopes', ['openid', 'profile', 'email']);
    Config::set('logto.oidc.pkce', true);
});

afterEach(function () {
    Mockery::close();
});

test('LogtoClient generates authorization URL with required parameters', function () {
    Config::set('logto.oidc', [
        'authorization_endpoint' => 'https://test.logto.app/oidc/auth',
        'redirect_uri' => '/auth/logto/callback',
        'scopes' => ['openid', 'profile', 'email'],
        'pkce' => true,
    ]);
    
    // Fill cache with mock OIDC config so discoverOidcConfig() is not called
    cache()->put('logto_oidc_config', [
        'authorization_endpoint' => 'https://test.logto.app/oidc/auth',
    ], 86400);
    
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    
    $url = $client->getAuthorizationUrl();
    
    expect($url)
        ->toContain('client_id=test-app-id')
        ->toContain('response_type=code')
        ->toContain('scope=openid+profile+email')
        ->toContain('redirect_uri=')
        ->toContain('state=')
        ->toContain('nonce=')
        ->toContain('code_challenge=')
        ->toContain('code_challenge_method=S256');
});

test('LogtoClient handles OIDC discovery errors', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    
    expect(fn() => $client->discoverOidcConfig())
        ->toThrow(LogtoException::class);
});

test('LogtoClient throws network error exception', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    
    try {
        $client->discoverOidcConfig();
        expect(true)->toBeFalse('Should have thrown exception');
    } catch (LogtoException $e) {
        expect($e->getCode())->toBe(404);
    }
});
