<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PortCheck::class)]
final class PortCheckTest
{
    public function preservesFields(): void
    {
        $check = new PortCheck(
            status: CheckStatus::OK,
            host: 'example.com',
            port: 443,
            connectTime: 0.12,
            error: null,
        );

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->host, 'example.com');
        Assert::same($check->port, 443);
        Assert::same($check->connectTime, 0.12);
        Assert::null($check->error);
    }

    #[DataProvider('invalidPortProvider')]
    public function throwsOnInvalidPort(int $port): void
    {
        try {
            new PortCheck(status: CheckStatus::UNKNOWN, host: 'example.com', port: $port, connectTime: 0.0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(\sprintf('Invalid port %d', $port));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'above range' => [65_536];
    }

    public function acceptsBoundaryPort1(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 1, connectTime: 0.0);

        Assert::same($check->port, 1);
    }

    public function acceptsBoundaryPort65535(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 65535, connectTime: 0.0);

        Assert::same($check->port, 65535);
    }

    public function acceptsZeroConnectTime(): void
    {
        $check = new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 443, connectTime: 0.0);

        Assert::same($check->connectTime, 0.0);
    }

    public function throwsOnNegativeConnectTime(): void
    {
        try {
            new PortCheck(status: CheckStatus::UNKNOWN, host: 'example.com', port: 443, connectTime: -1.0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Connect time must be greater than or equal to 0');
        }
    }

    public function serializesStatusEnumAndError(): void
    {
        $check = new PortCheck(status: CheckStatus::CRITICAL, host: 'example.com', port: 443, connectTime: 0.0, error: 'refused');

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'critical',
                'host' => 'example.com',
                'port' => 443,
                'connectTime' => 0.0,
                'error' => 'refused',
            ],
        );
    }
}
