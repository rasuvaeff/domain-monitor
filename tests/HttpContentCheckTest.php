<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheck;

#[CoversClass(HttpContentCheck::class)]
final class HttpContentCheckTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 200,
            finalUrl: 'https://example.com',
            requiredTextFound: true,
            forbiddenTextFound: false,
        );

        $this->assertSame(CheckStatus::OK, $check->status);
        $this->assertSame(200, $check->httpStatus);
        $this->assertSame('https://example.com', $check->finalUrl);
        $this->assertTrue($check->requiredTextFound);
        $this->assertFalse($check->forbiddenTextFound);
    }

    #[Test]
    public function allowsNetworkFailureStatus(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::UNKNOWN,
            httpStatus: 0,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        $this->assertSame(0, $check->httpStatus);
        $this->assertNull($check->finalUrl);
    }

    #[Test]
    #[DataProvider('throwsOnInvalidStatusProvider')]
    public function throwsOnInvalidStatus(int $httpStatus): void
    {
        $this->expectException(exception: InvalidArgumentException::class);

        new HttpContentCheck(
            status: CheckStatus::UNKNOWN,
            httpStatus: $httpStatus,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function throwsOnInvalidStatusProvider(): iterable
    {
        yield '99' => [99];
        yield '600' => [600];
    }

    #[Test]
    public function acceptsBoundaryHttpStatus100(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 100,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        $this->assertSame(100, $check->httpStatus);
    }

    #[Test]
    public function acceptsBoundaryHttpStatus599(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 599,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        $this->assertSame(599, $check->httpStatus);
    }
}
