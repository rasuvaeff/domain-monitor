<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortCheck;

#[CoversClass(PortCheck::class)]
final class PortCheckTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $check = new PortCheck(
            status: CheckStatus::OK,
            host: 'example.com',
            port: 443,
            connectTime: 0.12,
            error: null,
        );

        $this->assertSame(CheckStatus::OK, $check->status);
        $this->assertSame('example.com', $check->host);
        $this->assertSame(443, $check->port);
        $this->assertSame(0.12, $check->connectTime);
        $this->assertNull($check->error);
    }

    #[Test]
    #[DataProvider('invalidPortProvider')]
    public function throwsOnInvalidPort(int $port): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: \sprintf('Invalid port %d', $port));

        new PortCheck(status: CheckStatus::UNKNOWN, host: 'example.com', port: $port, connectTime: 0.0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'above range' => [65_536];
    }

    #[Test]
    public function acceptsBoundaryPort1(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 1, connectTime: 0.0);

        $this->assertSame(1, $check->port);
    }

    #[Test]
    public function acceptsBoundaryPort65535(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 65535, connectTime: 0.0);

        $this->assertSame(65535, $check->port);
    }

    #[Test]
    public function acceptsZeroConnectTime(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 443, connectTime: 0.0);

        $this->assertSame(0.0, $check->connectTime);
    }

    #[Test]
    public function throwsOnNegativeConnectTime(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Connect time must be greater than or equal to 0');

        new PortCheck(status: CheckStatus::UNKNOWN, host: 'example.com', port: 443, connectTime: -1.0);
    }
}
