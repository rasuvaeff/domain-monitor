<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;

#[CoversClass(DomainMonitorOptions::class)]
final class DomainMonitorOptionsTest extends TestCase
{
    #[Test]
    public function usesSensibleDefaults(): void
    {
        $options = new DomainMonitorOptions();

        $this->assertSame(443, $options->port);
        $this->assertSame(10.0, $options->timeoutSeconds);
        $this->assertSame('rasuvaeff/domain-monitor', $options->userAgent);
        $this->assertSame('GET', $options->httpMethod);
        $this->assertNull($options->expectedOrg);
        $this->assertSame(200, $options->expectedStatus);
        $this->assertNull($options->requiredText);
        $this->assertNull($options->forbiddenText);
    }

    #[Test]
    public function uppercasesHttpMethod(): void
    {
        $options = new DomainMonitorOptions(httpMethod: 'head');

        $this->assertSame('HEAD', $options->httpMethod);
    }

    #[Test]
    public function preservesCustomValues(): void
    {
        $options = new DomainMonitorOptions(
            port: 8443,
            timeoutSeconds: 30.0,
            userAgent: 'monitor/2.0',
            httpMethod: 'POST',
            expectedOrg: 'Example Inc.',
            expectedStatus: 204,
            requiredText: 'healthy',
            forbiddenText: 'error',
        );

        $this->assertSame(8443, $options->port);
        $this->assertSame(30.0, $options->timeoutSeconds);
        $this->assertSame('monitor/2.0', $options->userAgent);
        $this->assertSame('POST', $options->httpMethod);
        $this->assertSame('Example Inc.', $options->expectedOrg);
        $this->assertSame(204, $options->expectedStatus);
        $this->assertSame('healthy', $options->requiredText);
        $this->assertSame('error', $options->forbiddenText);
    }

    #[Test]
    #[DataProvider('invalidPortProvider')]
    public function throwsOnInvalidPort(int $port): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: \sprintf('Invalid port %d', $port));

        new DomainMonitorOptions(port: $port);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too high' => [65536];
    }

    #[Test]
    public function throwsOnZeroOrNegativeTimeout(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Timeout must be greater than 0');

        new DomainMonitorOptions(timeoutSeconds: 0.0);
    }

    #[Test]
    public function throwsOnEmptyUserAgent(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'User-Agent must not be empty');

        new DomainMonitorOptions(userAgent: '');
    }

    #[Test]
    public function throwsOnInvalidHttpMethod(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid HTTP method "GET POST"');

        new DomainMonitorOptions(httpMethod: 'GET POST');
    }

    #[Test]
    #[DataProvider('invalidExpectedStatusProvider')]
    public function throwsOnInvalidExpectedStatus(int $status): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: \sprintf('Invalid HTTP status %d', $status));

        new DomainMonitorOptions(expectedStatus: $status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidExpectedStatusProvider(): iterable
    {
        yield 'below range' => [99];
        yield 'above range' => [600];
    }

    #[Test]
    public function throwsOnBlankExpectedOrg(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Expected organization must not be empty');

        new DomainMonitorOptions(expectedOrg: '  ');
    }

    #[Test]
    public function acceptsBoundaryPorts(): void
    {
        $min = new DomainMonitorOptions(port: 1);
        $max = new DomainMonitorOptions(port: 65535);

        $this->assertSame(1, $min->port);
        $this->assertSame(65535, $max->port);
    }

    #[Test]
    public function acceptsBoundaryExpectedStatus(): void
    {
        $min = new DomainMonitorOptions(expectedStatus: 100);
        $max = new DomainMonitorOptions(expectedStatus: 599);

        $this->assertSame(100, $min->expectedStatus);
        $this->assertSame(599, $max->expectedStatus);
    }
}
