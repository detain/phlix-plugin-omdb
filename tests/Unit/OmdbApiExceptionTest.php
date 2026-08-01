<?php

/**
 * Unit tests for OmdbApiException.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests;

use Phlix\Plugins\Metadata\Omdb\OmdbApiException;
use PHPUnit\Framework\TestCase;

final class OmdbApiExceptionTest extends TestCase
{
    public function test_exception_can_be_instantiated(): void
    {
        $exception = new OmdbApiException('Test message');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_exception_can_be_thrown_and_caught(): void
    {
        $this->expectException(OmdbApiException::class);
        $this->expectExceptionMessage('API Error');

        throw new OmdbApiException('API Error');
    }

    public function test_exception_extends_runtime_exception(): void
    {
        $exception = new OmdbApiException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_exception_can_have_a_code(): void
    {
        $exception = new OmdbApiException('Error', 500);

        $this->assertSame(500, $exception->getCode());
    }

    public function test_exception_can_have_a_previous_exception(): void
    {
        $previous = new \RuntimeException('Previous');
        $exception = new OmdbApiException('Error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
