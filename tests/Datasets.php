<?php

/**
 * Test datasets for Logto Laravel SDK
 */

dataset('validUserInfo', [
    [
        'sub' => 'user-123',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'email_verified' => true,
        'picture' => 'https://example.com/avatar.jpg',
    ],
    [
        'sub' => 'user-456',
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'email_verified' => true,
        'picture' => null,
    ],
]);

dataset('validTokenResponses', [
    [
        'access_token' => 'access-token-abc123',
        'refresh_token' => 'refresh-token-xyz789',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_expires_in' => 86400,
    ],
    [
        'access_token' => 'access-token-def456',
        'refresh_token' => null,
        'token_type' => 'Bearer',
        'expires_in' => 7200,
    ],
]);

dataset('validOidcScopes', [
    ['openid'],
    ['openid', 'profile'],
    ['openid', 'email'],
    ['openid', 'profile', 'email'],
    ['openid', 'profile', 'email', 'phone'],
]);

dataset('invalidStates', [
    [''],
    ['invalid-state'],
    [null],
    [' '],
]);

dataset('validStates', function () {
    return [
        [bin2hex(random_bytes(20))],
        [bin2hex(random_bytes(30))],
        [bin2hex(random_bytes(40))],
    ];
});
