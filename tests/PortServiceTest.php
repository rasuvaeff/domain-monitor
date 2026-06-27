<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PortService::class)]
final class PortServiceTest
{
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

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->connectTime, 0.2);
    }

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

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->error, 'Connection refused');
    }

    public function throwsOnInvalidPort(): void
    {
        try {
            (new PortService())->check(host: 'example.com', port: 0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid port 0');
        }
    }

    public function throwsOnInvalidPortAboveRange(): void
    {
        try {
            (new PortService())->check(host: 'example.com', port: 65536);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid port 65536');
        }
    }

    public function acceptsBoundaryPort1(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return ['success' => true, 'connectTime' => 0.1, 'error' => null];
        });

        $result = $service->check(host: 'example.com', port: 1);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->port, 1);
    }

    public function acceptsBoundaryPort65535(): void
    {
        $service = new PortService(connector: static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            return ['success' => true, 'connectTime' => 0.1, 'error' => null];
        });

        $result = $service->check(host: 'example.com', port: 65535);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->port, 65535);
    }

    public function throwsOnZeroTimeout(): void
    {
        try {
            (new PortService())->check(host: 'example.com', port: 443, timeoutSeconds: 0.0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Timeout must be greater than 0');
        }
    }
}
