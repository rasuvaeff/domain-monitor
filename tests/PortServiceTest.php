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
}
