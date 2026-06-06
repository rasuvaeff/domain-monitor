<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortService;

#[CoversClass(PortService::class)]
final class PortServiceTest extends TestCase
{
    #[Test]
    public function returnsOkForSuccessfulConnection(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return [
                'success' => true,
                'connectTime' => 0.2,
                'error' => null,
            ];
        });

        $result = $service->check(host: 'example.com', port: 443);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(0.2, $result->connectTime);
    }

    #[Test]
    public function returnsCriticalForFailedConnection(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return [
                'success' => false,
                'connectTime' => 1.1,
                'error' => 'Connection refused',
            ];
        });

        $result = $service->check(host: 'example.com', port: 443);

        $this->assertSame(CheckStatus::CRITICAL, $result->status);
        $this->assertSame('Connection refused', $result->error);
    }

    #[Test]
    public function throwsOnInvalidPort(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid port 0');

        (new PortService())->check(host: 'example.com', port: 0);
    }

    #[Test]
    public function throwsOnInvalidPortAboveRange(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid port 65536');

        (new PortService())->check(host: 'example.com', port: 65536);
    }

    #[Test]
    public function acceptsBoundaryPort1(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return ['success' => true, 'connectTime' => 0.1, 'error' => null];
        });

        $result = $service->check(host: 'example.com', port: 1);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(1, $result->port);
    }

    #[Test]
    public function acceptsBoundaryPort65535(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return ['success' => true, 'connectTime' => 0.1, 'error' => null];
        });

        $result = $service->check(host: 'example.com', port: 65535);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(65535, $result->port);
    }

    #[Test]
    public function throwsOnZeroTimeout(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Timeout must be greater than 0');

        (new PortService())->check(host: 'example.com', port: 443, timeoutSeconds: 0.0);
    }
}
