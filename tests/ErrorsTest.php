<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\NetworkException;
use Sendly\Exceptions\WebhookSignatureException;

/**
 * Tests for all exception classes
 */
class ErrorsTest extends TestCase
{
    // ==================== SendlyException Tests ====================

    public function testSendlyExceptionDefaultConstructor(): void
    {
        $exception = new SendlyException();

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertSame('', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getErrorCode());
        $this->assertNull($exception->getDetails());
    }

    public function testSendlyExceptionWithMessage(): void
    {
        $exception = new SendlyException('Test error message');

        $this->assertSame('Test error message', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testSendlyExceptionWithMessageAndCode(): void
    {
        $exception = new SendlyException('Test error', 500);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
    }

    public function testSendlyExceptionWithPreviousException(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new SendlyException('Test error', 500, $previous);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testSendlyExceptionGetErrorCode(): void
    {
        $exception = new SendlyException('Test error');

        $this->assertNull($exception->getErrorCode());
    }

    public function testSendlyExceptionGetDetails(): void
    {
        $exception = new SendlyException('Test error');

        $this->assertNull($exception->getDetails());
    }

    // ==================== AuthenticationException Tests ====================

    public function testAuthenticationExceptionDefaultMessage(): void
    {
        $exception = new AuthenticationException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Invalid or missing API key', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
        $this->assertSame('AUTHENTICATION_ERROR', $exception->getErrorCode());
    }

    public function testAuthenticationExceptionCustomMessage(): void
    {
        $exception = new AuthenticationException('Custom auth error');

        $this->assertSame('Custom auth error', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
        $this->assertSame('AUTHENTICATION_ERROR', $exception->getErrorCode());
    }

    public function testAuthenticationExceptionInheritance(): void
    {
        $exception = new AuthenticationException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    // ==================== RateLimitException Tests ====================

    public function testRateLimitExceptionDefaultMessage(): void
    {
        $exception = new RateLimitException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Rate limit exceeded', $exception->getMessage());
        $this->assertSame(429, $exception->getCode());
        $this->assertSame('RATE_LIMIT_EXCEEDED', $exception->getErrorCode());
        $this->assertSame(0, $exception->getRetryAfter());
    }

    public function testRateLimitExceptionWithCustomMessage(): void
    {
        $exception = new RateLimitException('Custom rate limit message');

        $this->assertSame('Custom rate limit message', $exception->getMessage());
        $this->assertSame(429, $exception->getCode());
    }

    public function testRateLimitExceptionWithRetryAfter(): void
    {
        $exception = new RateLimitException('Rate limit exceeded', 60);

        $this->assertSame('Rate limit exceeded', $exception->getMessage());
        $this->assertSame(60, $exception->getRetryAfter());
        $this->assertSame(429, $exception->getCode());
    }

    public function testRateLimitExceptionWithZeroRetryAfter(): void
    {
        $exception = new RateLimitException('Rate limit exceeded', 0);

        $this->assertSame(0, $exception->getRetryAfter());
    }

    public function testRateLimitExceptionWithLargeRetryAfter(): void
    {
        $exception = new RateLimitException('Rate limit exceeded', 3600);

        $this->assertSame(3600, $exception->getRetryAfter());
    }

    // ==================== InsufficientCreditsException Tests ====================

    public function testInsufficientCreditsExceptionDefaultMessage(): void
    {
        $exception = new InsufficientCreditsException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Insufficient credits', $exception->getMessage());
        $this->assertSame(402, $exception->getCode());
        $this->assertSame('INSUFFICIENT_CREDITS', $exception->getErrorCode());
    }

    public function testInsufficientCreditsExceptionCustomMessage(): void
    {
        $exception = new InsufficientCreditsException('Not enough credits to send');

        $this->assertSame('Not enough credits to send', $exception->getMessage());
        $this->assertSame(402, $exception->getCode());
    }

    // ==================== ValidationException Tests ====================

    public function testValidationExceptionDefaultMessage(): void
    {
        $exception = new ValidationException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Validation failed', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
        $this->assertSame('VALIDATION_ERROR', $exception->getErrorCode());
        $this->assertNull($exception->getDetails());
    }

    public function testValidationExceptionCustomMessage(): void
    {
        $exception = new ValidationException('Invalid phone number');

        $this->assertSame('Invalid phone number', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
    }

    public function testValidationExceptionWithDetails(): void
    {
        $details = [
            'phone' => 'Invalid format',
            'text' => 'Too long',
        ];
        $exception = new ValidationException('Validation failed', $details);

        $this->assertSame('Validation failed', $exception->getMessage());
        $this->assertSame($details, $exception->getDetails());
    }

    public function testValidationExceptionWithEmptyDetails(): void
    {
        $exception = new ValidationException('Validation failed', []);

        $this->assertSame([], $exception->getDetails());
    }

    public function testValidationExceptionWithNullDetails(): void
    {
        $exception = new ValidationException('Validation failed', null);

        $this->assertNull($exception->getDetails());
    }

    // ==================== NotFoundException Tests ====================

    public function testNotFoundExceptionDefaultMessage(): void
    {
        $exception = new NotFoundException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Resource not found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
        $this->assertSame('NOT_FOUND', $exception->getErrorCode());
    }

    public function testNotFoundExceptionCustomMessage(): void
    {
        $exception = new NotFoundException('Message not found');

        $this->assertSame('Message not found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
    }

    // ==================== NetworkException Tests ====================

    public function testNetworkExceptionDefaultMessage(): void
    {
        $exception = new NetworkException();

        $this->assertInstanceOf(SendlyException::class, $exception);
        $this->assertSame('Network error occurred', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('NETWORK_ERROR', $exception->getErrorCode());
    }

    public function testNetworkExceptionCustomMessage(): void
    {
        $exception = new NetworkException('Connection timeout');

        $this->assertSame('Connection timeout', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testNetworkExceptionWithCode(): void
    {
        $exception = new NetworkException('Connection failed', 503);

        $this->assertSame('Connection failed', $exception->getMessage());
        $this->assertSame(503, $exception->getCode());
    }

    public function testNetworkExceptionWithPreviousException(): void
    {
        $previous = new \Exception('Socket error');
        $exception = new NetworkException('Connection failed', 0, $previous);

        $this->assertSame('Connection failed', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    // ==================== WebhookSignatureException Tests ====================

    public function testWebhookSignatureExceptionDefaultMessage(): void
    {
        $exception = new WebhookSignatureException();

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertSame('Invalid webhook signature', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testWebhookSignatureExceptionCustomMessage(): void
    {
        $exception = new WebhookSignatureException('Signature verification failed');

        $this->assertSame('Signature verification failed', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testWebhookSignatureExceptionNotExtendingSendlyException(): void
    {
        $exception = new WebhookSignatureException();

        // WebhookSignatureException extends Exception directly, not SendlyException
        $this->assertNotInstanceOf(SendlyException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    // ==================== Exception Hierarchy Tests ====================

    public function testExceptionHierarchy(): void
    {
        $exceptions = [
            new AuthenticationException(),
            new RateLimitException(),
            new InsufficientCreditsException(),
            new ValidationException(),
            new NotFoundException(),
            new NetworkException(),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(SendlyException::class, $exception);
            $this->assertInstanceOf(\Exception::class, $exception);
        }
    }

    public function testAllExceptionsHaveErrorCodes(): void
    {
        $exceptions = [
            [new AuthenticationException(), 'AUTHENTICATION_ERROR'],
            [new RateLimitException(), 'RATE_LIMIT_EXCEEDED'],
            [new InsufficientCreditsException(), 'INSUFFICIENT_CREDITS'],
            [new ValidationException(), 'VALIDATION_ERROR'],
            [new NotFoundException(), 'NOT_FOUND'],
            [new NetworkException(), 'NETWORK_ERROR'],
        ];

        foreach ($exceptions as [$exception, $expectedCode]) {
            $this->assertSame($expectedCode, $exception->getErrorCode());
        }
    }

    public function testAllExceptionsHaveCorrectHttpCodes(): void
    {
        $exceptions = [
            [new AuthenticationException(), 401],
            [new RateLimitException(), 429],
            [new InsufficientCreditsException(), 402],
            [new ValidationException(), 400],
            [new NotFoundException(), 404],
            [new NetworkException(), 0],
        ];

        foreach ($exceptions as [$exception, $expectedCode]) {
            $this->assertSame($expectedCode, $exception->getCode());
        }
    }

    // ==================== Exception Catching Tests ====================

    public function testCatchingSpecificException(): void
    {
        try {
            throw new AuthenticationException('Test auth error');
        } catch (AuthenticationException $e) {
            $this->assertSame('Test auth error', $e->getMessage());
            $this->assertTrue(true); // Assert that we caught it
        }
    }

    public function testCatchingAsBaseException(): void
    {
        try {
            throw new ValidationException('Test validation error');
        } catch (SendlyException $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertSame('Test validation error', $e->getMessage());
        }
    }

    public function testCatchingMultipleExceptions(): void
    {
        $caught = false;

        try {
            throw new RateLimitException('Rate limited', 30);
        } catch (AuthenticationException $e) {
            $this->fail('Should not catch AuthenticationException');
        } catch (RateLimitException $e) {
            $caught = true;
            $this->assertSame(30, $e->getRetryAfter());
        } catch (SendlyException $e) {
            $this->fail('Should catch specific exception first');
        }

        $this->assertTrue($caught);
    }

    // ==================== Edge Cases ====================

    public function testExceptionWithVeryLongMessage(): void
    {
        $longMessage = str_repeat('Error message. ', 1000);
        $exception = new SendlyException($longMessage);

        $this->assertSame($longMessage, $exception->getMessage());
    }

    public function testExceptionWithSpecialCharacters(): void
    {
        $message = "Error with special chars: \n\t\r\"'\\";
        $exception = new ValidationException($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testExceptionWithUnicodeMessage(): void
    {
        $message = 'Error: 你好世界 🌍';
        $exception = new SendlyException($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testValidationExceptionWithComplexDetails(): void
    {
        $details = [
            'phone' => ['error' => 'Invalid format', 'received' => '1234567890'],
            'text' => ['error' => 'Too long', 'length' => 2000, 'max' => 1600],
            'metadata' => ['field' => 'value'],
        ];
        $exception = new ValidationException('Multiple validation errors', $details);

        $this->assertSame($details, $exception->getDetails());
        $this->assertIsArray($exception->getDetails());
        $this->assertArrayHasKey('phone', $exception->getDetails());
        $this->assertArrayHasKey('text', $exception->getDetails());
    }
}
