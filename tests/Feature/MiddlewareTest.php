<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use TIVENTS\LogtoLaravelSdk\Middleware\AuthenticateWithLogto;

beforeEach(function () {
    Config::set('logto.guard.name', 'logto');
    Config::set('logto.endpoint', 'https://test.logto.app');
    
    Session::start();
});

afterEach(function () {
    Session::flush();
    Mockery::close();
});

test('AuthenticateWithLogto allows authenticated users', function () {
    // Mock the guard
    $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('check')->andReturn(true);
    $guard->shouldReceive('getTokenManager')->andReturnNull();
    
    Auth::shouldReceive('guard')->with('logto')->andReturn($guard);
    
    $middleware = new AuthenticateWithLogto();
    $request = Request::create('/test');
    
    $next = function ($request) {
        return response('Success');
    };
    
    $response = $middleware->handle($request, $next, 'logto');
    
    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('Success');
});

test('AuthenticateWithLogto redirects unauthenticated users for web requests', function () {
    // Mock the guard
    $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('check')->andReturn(false);
    
    Auth::shouldReceive('guard')->with('logto')->andReturn($guard);
    
    // Mock the Logto client
    $client = Mockery::mock(\TIVENTS\LogtoLaravelSdk\Services\LogtoClient::class);
    $client->shouldReceive('getAuthorizationUrl')->andReturn('https://test.logto.app/oidc/auth?client_id=test');
    
    app()->instance('logto', $client);
    
    $middleware = new AuthenticateWithLogto();
    $request = Request::create('/test');
    
    $next = function ($request) {
        return response('Should not be called');
    };
    
    $response = $middleware->handle($request, $next, 'logto');
    
    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('https://test.logto.app/oidc/auth');
});

test('AuthenticateWithLogto returns JSON for API requests', function () {
    // Mock the guard
    $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('check')->andReturn(false);
    
    Auth::shouldReceive('guard')->with('logto')->andReturn($guard);
    
    $middleware = new AuthenticateWithLogto();
    $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_Accept' => 'application/json']);
    
    $next = function ($request) {
        return response('Should not be called');
    };
    
    $response = $middleware->handle($request, $next, 'logto');
    
    expect($response->getStatusCode())->toBe(401);
    
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Unauthenticated.');
});

test('AuthenticateWithLogto uses custom guard from parameters', function () {
    Config::set('logto.guard.name', 'custom-guard');
    
    $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('check')->andReturn(true);
    $guard->shouldReceive('getTokenManager')->andReturnNull();
    
    Auth::shouldReceive('guard')->with('custom-guard')->andReturn($guard);
    
    $middleware = new AuthenticateWithLogto();
    $request = Request::create('/test');
    
    $next = function ($request) {
        return response('Success');
    };
    
    $response = $middleware->handle($request, $next, 'custom-guard');
    
    expect($response->getStatusCode())->toBe(200);
});
