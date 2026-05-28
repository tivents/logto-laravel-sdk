<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

beforeEach(function () {
    Config::set('logto.tokens.access_token_lifetime', 3600);
    Config::set('logto.tokens.refresh_token_lifetime', 86400);
    Config::set('logto.tokens.store_encrypted', false);
    
    // Set encryption key for tests that use encrypted storage
    Config::set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    Config::set('app.cipher', 'AES-256-CBC');
    
    Cache::flush();
});

afterEach(function () {
    Cache::flush();
    Mockery::close();
});

test('TokenManager stores and retrieves access token', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'test-user-123';
    $accessToken = 'test-access-token';
    $tokens = [
        'access_token' => $accessToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    $retrievedToken = $tokenManager->getAccessToken($userId);
    
    expect($retrievedToken)->toBe($accessToken);
    expect($tokenManager->hasValidTokens($userId))->toBeTrue();
});

test('TokenManager stores and retrieves refresh token', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'test-user-456';
    $refreshToken = 'test-refresh-token';
    $tokens = [
        'access_token' => 'access-token',
        'refresh_token' => $refreshToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_expires_in' => 86400,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    $retrievedRefreshToken = $tokenManager->getRefreshToken($userId);
    
    expect($retrievedRefreshToken)->toBe($refreshToken);
});

test('TokenManager clears tokens for user', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'test-user-789';
    $tokens = [
        'access_token' => 'test-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    $tokenManager->clearTokens($userId);
    
    expect($tokenManager->getAccessToken($userId))->toBeNull();
    expect($tokenManager->hasValidTokens($userId))->toBeFalse();
});

test('TokenManager handles encrypted token storage', function () {
    Config::set('logto.tokens.store_encrypted', true);
    
    $tokenManager = new TokenManager();
    
    $userId = 'encrypted-user';
    $accessToken = 'secret-token-value';
    $tokens = [
        'access_token' => $accessToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    $retrievedToken = $tokenManager->getAccessToken($userId);
    
    expect($retrievedToken)->toBe($accessToken);
});

test('TokenManager detects expired tokens', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'expired-user';
    $tokens = [
        'access_token' => 'expired-token',
        'token_type' => 'Bearer',
        'expires_in' => 1, // 1 second
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    expect($tokenManager->hasValidTokens($userId))->toBeTrue();
    
    // Wait for token to expire
    sleep(2);
    
    expect($tokenManager->hasValidTokens($userId))->toBeFalse();
    expect($tokenManager->getAccessToken($userId))->toBeNull();
});

test('TokenManager gets token type', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'token-type-user';
    $tokens = [
        'access_token' => 'test-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    expect($tokenManager->getTokenType($userId))->toBe('Bearer');
});

test('TokenManager gets token expiration time', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'expiration-user';
    $tokens = [
        'access_token' => 'test-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ];
    
    $tokenManager->storeTokens($userId, $tokens);
    
    $expiration = $tokenManager->getTokenExpiration($userId);
    
    expect($expiration)->toBeGreaterThan(time());
    expect($expiration)->toBeLessThan(time() + 4000);
});

test('TokenManager returns null for non-existent tokens', function () {
    $tokenManager = new TokenManager();
    
    $userId = 'non-existent-user';
    
    expect($tokenManager->getAccessToken($userId))->toBeNull();
    expect($tokenManager->getRefreshToken($userId))->toBeNull();
    expect($tokenManager->hasValidTokens($userId))->toBeFalse();
    expect($tokenManager->getTokenExpiration($userId))->toBeNull();
});
