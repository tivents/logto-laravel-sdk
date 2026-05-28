<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use TIVENTS\LogtoLaravelSdk\Guards\LogtoGuard;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

beforeEach(function () {
    Config::set('logto.app_id', 'test-app-id');
    Config::set('logto.app_secret', 'test-app-secret');
    Config::set('logto.endpoint', 'https://test.logto.app');
    Config::set('logto.guard.name', 'logto');
    Config::set('logto.user.model', \App\Models\User::class);
    Config::set('logto.user.auto_create', true);
    Config::set('logto.user.auto_update', true);
    
    Session::start();
});

afterEach(function () {
    Session::flush();
    Mockery::close();
});

test('LogtoGuard user returns null when no user in session', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect($guard->user())->toBeNull();
    expect($guard->check())->toBeFalse();
    expect($guard->guest())->toBeTrue();
});

test('LogtoGuard id returns null when no user in session', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect($guard->id())->toBeNull();
});

test('LogtoGuard login sets user in session', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    // Create a mock user
    $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(123);
    
    $guard->login($user);
    
    expect(Session::get('logto_user_id'))->toBe(123);
    expect(Session::get('logto_authenticated'))->toBeTrue();
});

test('LogtoGuard logout clears session', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    // Set some session data
    Session::put('logto_user_id', 123);
    Session::put('logto_tokens', ['access_token' => 'test']);
    Session::put('logto_authenticated', true);
    
    $guard->logout();
    
    expect(Session::get('logto_user_id'))->toBeNull();
    expect(Session::get('logto_tokens'))->toBeNull();
    expect(Session::get('logto_authenticated'))->toBeNull();
});

test('LogtoGuard setUser sets user property', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(456);
    
    $result = $guard->setUser($user);
    
    expect($result)->toBe($guard);
    expect(Session::get('logto_user_id'))->toBe(456);
});

test('LogtoGuard authenticate throws exception when user not authenticated', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect(fn() => $guard->authenticate())->toThrow(\TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException::class);
});

test('LogtoGuard validate returns false for OAuth guard', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect($guard->validate([]))->toBeFalse();
    expect($guard->validate(['email' => 'test@example.com', 'password' => 'password']))->toBeFalse();
});

test('LogtoGuard attempt returns false for OAuth guard', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect($guard->attempt([]))->toBeFalse();
});

test('LogtoGuard once returns null for OAuth guard', function () {
    $tokenManager = new TokenManager();
    $client = new LogtoClient($tokenManager);
    $request = Request::create('/');
    
    $guard = new LogtoGuard($client, $tokenManager, $request);
    
    expect($guard->once([]))->toBeNull();
});
