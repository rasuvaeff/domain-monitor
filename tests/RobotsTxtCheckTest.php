<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\RobotsTxtCheck;

#[CoversClass(RobotsTxtCheck::class)]
final class RobotsTxtCheckTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $check = new RobotsTxtCheck(
            status: CheckStatus::OK,
            httpStatus: 200,
            exists: true,
            sitemaps: ['https://example.com/sitemap.xml'],
        );

        $this->assertSame(CheckStatus::OK, $check->status);
        $this->assertSame(200, $check->httpStatus);
        $this->assertTrue($check->exists);
        $this->assertSame(['https://example.com/sitemap.xml'], $check->sitemaps);
    }

    #[Test]
    public function throwsOnInvalidStatus(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid HTTP status 42');

        new RobotsTxtCheck(status: CheckStatus::UNKNOWN, httpStatus: 42, exists: false, sitemaps: []);
    }
}
