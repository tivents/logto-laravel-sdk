<?php

use TIVENTS\LogtoLaravelSdk\Exceptions\LogtoException;

test('LogtoException authenticationFailed creates correct exception', function () {
    $exception = LogtoException::authenticationFailed('Test authentication error');
    
    expect($exception->getMessage())->toBe('Test authentication error');
    expect($exception->getCode())->toBe(401);
});

test('LogtoException invalidToken creates correct exception', function () {
    $exception = LogtoException::invalidToken('Invalid token provided');
    
    expect($exception->getMessage())->toBe('Invalid token provided');
    expect($exception->getCode())->toBe(401);
});

test('LogtoException configurationError creates correct exception', function () {
    $exception = LogtoException::configurationError('Invalid configuration');
    
    expect($exception->getMessage())->toBe('Invalid configuration');
    expect($exception->getCode())->toBe(500);
});

test('LogtoException apiError creates correct exception with default code', function () {
    $exception = LogtoException::apiError('API request failed');
    
    expect($exception->getMessage())->toBe('API request failed');
    expect($exception->getCode())->toBe(500);
});

test('LogtoException apiError creates correct exception with custom code', function () {
    $exception = LogtoException::apiError('Bad request', 400);
    
    expect($exception->getMessage())->toBe('Bad request');
    expect($exception->getCode())->toBe(400);
});

test('LogtoException networkError creates correct exception', function () {
    $exception = LogtoException::networkError('Connection timeout');
    
    expect($exception->getMessage())->toBe('Connection timeout');
    expect($exception->getCode())->toBe(503);
});

test('LogtoException extends base Exception', function () {
    $exception = LogtoException::authenticationFailed('Test');
    
    expect($exception)->toBeInstanceOf(Exception::class);
});

test('LogtoException uses default messages when none provided', function () {
    $authException = LogtoException::authenticationFailed();
    expect($authException->getMessage())->toBe('Logto authentication failed');
    
    $tokenException = LogtoException::invalidToken();
    expect($tokenException->getMessage())->toBe('Invalid or expired token');
    
    $configException = LogtoException::configurationError();
    expect($configException->getMessage())->toBe('Logto configuration is invalid');
    
    $apiException = LogtoException::apiError();
    expect($apiException->getMessage())->toBe('Logto API request failed');
    
    $networkException = LogtoException::networkError();
    expect($networkException->getMessage())->toBe('Network error when connecting to Logto');
});
