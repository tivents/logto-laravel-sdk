<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;

// Create the Laravel application
$app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Set test configuration
Config::set('app.env', 'testing');
Config::set('app.key', 'base64:X7t7nJpQJ2JJz8A2vX9gYyQ5Y+8Bb4L3jJx5sV9K1A=');
Config::set('app.cipher', 'AES-256-CBC');
Config::set('session.driver', 'array');
Config::set('cache.default', 'array');

// Load Pest helpers
uses()->group('helpers');

// Define global beforeEach and afterEach hooks
beforeEach(function () {
    // Reset session for each test
    Session::start();
    
    // Set default Logto configuration for tests
    Config::set('logto.app_id', 'test-app-id');
    Config::set('logto.app_secret', 'test-app-secret');
    Config::set('logto.endpoint', 'https://test.logto.app');
    Config::set('logto.guard.name', 'logto');
    Config::set('logto.oidc.redirect_uri', '/auth/logto/callback');
    Config::set('logto.oidc.scopes', ['openid', 'profile', 'email']);
    Config::set('logto.oidc.pkce', true);
    Config::set('logto.tokens.store_encrypted', false);
    Config::set('logto.user.auto_create', true);
    Config::set('logto.user.auto_update', true);
});

afterEach(function () {
    Session::flush();
    Mockery::close();
});

// Load all test files
uses()->group('unit');
