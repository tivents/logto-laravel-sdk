<?php

namespace TIVENTS\LogtoLaravelSdk\Exceptions;

use Exception;

class LogtoException extends Exception
{
    /**
     * Create a new exception for authentication errors.
     */
    public static function authenticationFailed(string $message = 'Logto authentication failed'): self
    {
        return new self($message, 401);
    }

    /**
     * Create a new exception for token errors.
     */
    public static function invalidToken(string $message = 'Invalid or expired token'): self
    {
        return new self($message, 401);
    }

    /**
     * Create a new exception for configuration errors.
     */
    public static function configurationError(string $message = 'Logto configuration is invalid'): self
    {
        return new self($message, 500);
    }

    /**
     * Create a new exception for API errors.
     */
    public static function apiError(string $message = 'Logto API request failed', int $code = 500): self
    {
        return new self($message, $code);
    }

    /**
     * Create a new exception for network errors.
     */
    public static function networkError(string $message = 'Network error when connecting to Logto'): self
    {
        return new self($message, 503);
    }
}
