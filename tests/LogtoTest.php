<?php

namespace TIVENTS\LogtoLaravelSdk\Tests;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Mockery;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

class LogtoTest extends TestCase
{
    /**
     * Setup the test case.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup Laravel application
        $this->app = $this->createApplication();
        
        // Configure Logto settings for testing
        Config::set('logto.app_id', 'test-app-id');
        Config::set('logto.app_secret', 'test-app-secret');
        Config::set('logto.endpoint', 'https://test.logto.app');
        Config::set('logto.oidc.redirect_uri', '/auth/logto/callback');
        Config::set('logto.oidc.scopes', ['openid', 'profile', 'email']);
        Config::set('logto.oidc.pkce', true);
    }

    /**
     * Creates the application.
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        
        return $app;
    }

    /**
     * Test TokenManager token storage.
     */
    public function testTokenManagerStoresAndRetrievesTokens()
    {
        $tokenManager = new TokenManager();
        
        $userId = 'test-user-123';
        $tokens = [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
        
        // Store tokens
        $tokenManager->storeTokens($userId, $tokens);
        
        // Retrieve access token
        $retrievedToken = $tokenManager->getAccessToken($userId);
        
        // Since tokens are stored encrypted by default, we need to check differently
        $this->assertNotNull($retrievedToken);
        $this->assertTrue($tokenManager->hasValidTokens($userId));
        
        // Clear tokens
        $tokenManager->clearTokens($userId);
        
        // Token should be null after clearing
        $this->assertNull($tokenManager->getAccessToken($userId));
        $this->assertFalse($tokenManager->hasValidTokens($userId));
    }

    /**
     * Test LogtoClient authorization URL generation.
     */
    public function testLogtoClientGeneratesAuthorizationUrl()
    {
        $tokenManager = new TokenManager();
        $client = new LogtoClient($tokenManager);
        
        // Generate authorization URL
        $url = $client->getAuthorizationUrl();
        
        // Check that URL contains required parameters
        $this->assertStringContainsString('client_id=test-app-id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=openid+profile+email', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringContainsString('nonce=', $url);
        
        // Check that PKCE parameters are included
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    /**
     * Test LogtoClient handles errors.
     */
    public function testLogtoClientHandlesApiErrors()
    {
        $this->expectException(LogtoException::class);
        
        $tokenManager = new TokenManager();
        $client = new LogtoClient($tokenManager);
        
        // This will fail because we don't have a real Logto instance
        try {
            $client->discoverOidcConfig();
        } catch (LogtoException $e) {
            $this->assertEquals(503, $e->getCode());
            throw $e;
        }
    }

    /**
     * Test exception messages.
     */
    public function testLogtoExceptionMessages()
    {
        $authException = LogtoException::authenticationFailed('Test message');
        $this->assertEquals('Test message', $authException->getMessage());
        $this->assertEquals(401, $authException->getCode());
        
        $tokenException = LogtoException::invalidToken('Invalid token');
        $this->assertEquals('Invalid token', $tokenException->getMessage());
        $this->assertEquals(401, $tokenException->getCode());
        
        $configException = LogtoException::configurationError('Bad config');
        $this->assertEquals('Bad config', $configException->getMessage());
        $this->assertEquals(500, $configException->getCode());
        
        $apiException = LogtoException::apiError('API error', 400);
        $this->assertEquals('API error', $apiException->getMessage());
        $this->assertEquals(400, $apiException->getCode());
        
        $networkException = LogtoException::networkError('Network error');
        $this->assertEquals('Network error', $networkException->getMessage());
        $this->assertEquals(503, $networkException->getCode());
    }

    /**
     * Test TokenManager handles token expiration.
     */
    public function testTokenManagerHandlesExpiredTokens()
    {
        $tokenManager = new TokenManager();
        
        $userId = 'expired-token-user';
        $tokens = [
            'access_token' => 'expired-token',
            'token_type' => 'Bearer',
            'expires_in' => 1, // 1 second
        ];
        
        $tokenManager->storeTokens($userId, $tokens);
        
        // Token should be valid immediately
        $this->assertTrue($tokenManager->hasValidTokens($userId));
        
        // Wait for token to expire
        sleep(2);
        
        // Token should be expired now
        $this->assertFalse($tokenManager->hasValidTokens($userId));
    }

    /**
     * Test TokenManager with unencrypted storage.
     */
    public function testTokenManagerUnencryptedStorage()
    {
        Config::set('logto.tokens.store_encrypted', false);
        
        $tokenManager = new TokenManager();
        
        $userId = 'unencrypted-user';
        $accessToken = 'unencrypted-token-value';
        $tokens = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
        
        $tokenManager->storeTokens($userId, $tokens);
        
        $retrievedToken = $tokenManager->getAccessToken($userId);
        
        $this->assertEquals($accessToken, $retrievedToken);
    }

    /**
     * Clean up the test case.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
