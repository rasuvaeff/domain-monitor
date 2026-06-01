<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;

#[CoversClass(HttpProbeOptions::class)]
final class HttpProbeOptionsTest extends TestCase
{
    #[Test]
    public function normalizesDefaultsAndMethod(): void
    {
        $options = new HttpProbeOptions(method: 'head');

        $this->assertSame('HEAD', $options->method);
        $this->assertSame([], $options->headers);
        $this->assertSame(5.0, $options->timeoutSeconds);
        $this->assertSame('rasuvaeff/domain-monitor', $options->userAgent);
    }

    #[Test]
    public function preservesCustomValues(): void
    {
        $options = new HttpProbeOptions(
            method: 'POST',
            headers: ['Accept' => 'application/json'],
            timeoutSeconds: 12.5,
            userAgent: 'custom/2.0',
        );

        $this->assertSame('POST', $options->method);
        $this->assertSame(['Accept' => 'application/json'], $options->headers);
        $this->assertSame(12.5, $options->timeoutSeconds);
        $this->assertSame('custom/2.0', $options->userAgent);
    }

    #[Test]
    public function throwsOnEmptyHeaderName(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Header names must be non-empty strings');

        new HttpProbeOptions(headers: ['' => 'value']);
    }

    #[Test]
    public function throwsOnEmptyUserAgent(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'User-Agent must not be empty');

        new HttpProbeOptions(userAgent: '');
    }

    #[Test]
    public function throwsOnInvalidMethod(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid HTTP method "GET1"');

        new HttpProbeOptions(method: 'GET1');
    }

    #[Test]
    public function throwsOnInvalidTimeout(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Timeout must be greater than 0');

        new HttpProbeOptions(timeoutSeconds: 0.0);
    }
}
