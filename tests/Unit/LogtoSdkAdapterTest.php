<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Logto\Sdk\Storage\StorageKey;
use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;
use TIVENTS\LogtoLaravelSdk\Services\LogtoSdkAdapter;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

beforeEach(function () {
    Config::set('logto.app_id', 'test-app-id');
    Config::set('logto.app_secret', 'test-app-secret');
    Config::set('logto.endpoint', 'https://test.logto.app');
    Config::set('logto.oidc.scopes', ['openid', 'profile', 'email']);
    
    Session::flush();
});

afterEach(function () {
    Session::flush();
    Mockery::close();
});

describe('LogtoSdkAdapter', function () {
    
    test('adapter can be instantiated', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        expect($sdkAdapter)->toBeInstanceOf(LogtoSdkAdapter::class);
    });

    test('token manager can be accessed', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        expect($sdkAdapter->getTokenManager())->toBeInstanceOf(TokenManager::class);
    });

    test('missing app ID throws configuration error', function () {
        Config::set('logto.app_id', '');
        
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        expect(fn() => $sdkAdapter->getSdkClient())
            ->toThrow(LogtoException::class, 'LOGTO_APP_ID is not configured');
    });

    test('missing app secret throws configuration error', function () {
        Config::set('logto.app_secret', '');
        
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        expect(fn() => $sdkAdapter->getSdkClient())
            ->toThrow(LogtoException::class, 'LOGTO_APP_SECRET is not configured');
    });

    test('missing endpoint throws configuration error', function () {
        Config::set('logto.endpoint', '');
        
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        expect(fn() => $sdkAdapter->getSdkClient())
            ->toThrow(LogtoException::class, 'LOGTO_ENDPOINT is not configured');
    });

    test('storage get and set methods with StorageKey enum', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        $key = StorageKey::idToken;
        $value = 'test-id-token';

        $sdkAdapter->set($key, $value);
        $retrieved = $sdkAdapter->get($key);

        expect($retrieved)->toBe($value);
    });

    test('storage delete method with StorageKey enum', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        $key = StorageKey::refreshToken;
        $value = 'test-refresh-token';

        $sdkAdapter->set($key, $value);
        $sdkAdapter->delete($key);
        $retrieved = $sdkAdapter->get($key);

        expect($retrieved)->toBeNull();
    });

    test('storage key mapping with StorageKey enum', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        $key = StorageKey::signInSession;
        $value = json_encode(['redirectUri' => 'test', 'codeVerifier' => 'test', 'state' => 'test']);

        $sdkAdapter->set($key, $value);
        
        // Check that the key is prefixed in session
        $sessionKey = 'logto_sdk_' . $key->value;
        $sessionValue = Session::get($sessionKey);
        
        expect($sessionValue)->toBe($value);
    });

    test('sign-in session storage with array', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        $sessionData = [
            'redirectUri' => 'https://test.com/callback',
            'codeVerifier' => 'test-verifier',
            'state' => 'test-state'
        ];

        $sdkAdapter->setSignInSession($sessionData);
        $retrieved = $sdkAdapter->getSignInSession();

        expect($retrieved)->not->toBeNull();
        expect($retrieved['redirectUri'])->toBe('https://test.com/callback');
        expect($retrieved['codeVerifier'])->toBe('test-verifier');
        expect($retrieved['state'])->toBe('test-state');
    });

    test('sign-in session retrieval when not set', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        $session = $sdkAdapter->getSignInSession();
        
        expect($session)->toBeNull();
    });

    test('clear session method', function () {
        $tokenManager = new TokenManager();
        $sdkAdapter = new LogtoSdkAdapter($tokenManager);
        
        // Set some test data
        Session::put('logto_tokens', ['access_token' => 'test']);
        Session::put('logto_user_info', ['sub' => '123']);
        
        // Set SDK storage data
        $sdkAdapter->set(StorageKey::idToken, 'test-id-token');
        $sdkAdapter->set(StorageKey::refreshToken, 'test-refresh-token');

        $sdkAdapter->clearSession();

        // Check that Laravel session keys are cleared
        expect(Session::get('logto_tokens'))->toBeNull();
        expect(Session::get('logto_user_info'))->toBeNull();
        
        // Check that SDK storage keys are cleared
        expect($sdkAdapter->get(StorageKey::idToken))->toBeNull();
        expect($sdkAdapter->get(StorageKey::refreshToken))->toBeNull();
    });
});
