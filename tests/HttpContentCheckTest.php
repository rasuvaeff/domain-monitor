<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
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
    public function throwsOnInvalidStatus(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid HTTP status 600');

        new HttpContentCheck(
            status: CheckStatus::UNKNOWN,
            httpStatus: 600,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );
    }
}
