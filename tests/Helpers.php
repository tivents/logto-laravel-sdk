<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

/**
 * Create a new LogtoClient instance for testing.
 */
function createLogtoClient(bool $withEncryption = false): LogtoClient
{
    Config::set('logto.tokens.store_encrypted', $withEncryption);
    
    $tokenManager = new TokenManager();
    return new LogtoClient($tokenManager);
}

/**
 * Create a new TokenManager instance for testing.
 */
function createTokenManager(bool $withEncryption = false): TokenManager
{
    Config::set('logto.tokens.store_encrypted', $withEncryption);
    return new TokenManager();
}

/**
 * Setup a mock user in session for testing.
 */
function setupMockUser(int $userId = 123): void
{
    Session::put('logto_user_id', $userId);
    Session::put('logto_authenticated', true);
    Session::put('logto_authenticated_at', time());
    Session::put('logto_user_info', [
        'sub' => (string) $userId,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'email_verified' => true,
    ]);
}

/**
 * Setup mock tokens in session for testing.
 */
function setupMockTokens(string $accessToken = 'test-access-token', string $refreshToken = 'test-refresh-token'): void
{
    Session::put('logto_tokens', [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]);
}

/**
 * Assert that a response is a redirect.
 */
function assertRedirect(\Illuminate\Http\RedirectResponse $response, string $expectedUrl): void
{
    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe($expectedUrl);
}

/**
 * Assert that a response is a JSON response with expected data.
 */
function assertJsonResponse(\Illuminate\Http\Response $response, array $expectedData, int $expectedStatus = 200): void
{
    expect($response->getStatusCode())->toBe($expectedStatus);
    
    $actualData = json_decode($response->getContent(), true);
    
    foreach ($expectedData as $key => $value) {
        expect($actualData[$key] ?? null)->toBe($value);
    }
}
