<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\RobotsTxtCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RobotsTxtCheck::class)]
final class RobotsTxtCheckTest
{
    public function preservesFields(): void
    {
        $check = new RobotsTxtCheck(
            status: CheckStatus::OK,
            httpStatus: 200,
            exists: true,
            sitemaps: ['https://example.com/sitemap.xml'],
        );

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->httpStatus, 200);
        Assert::true($check->exists);
        Assert::same($check->sitemaps, ['https://example.com/sitemap.xml']);
    }

    public function throwsOnInvalidStatus(): void
    {
        try {
            new RobotsTxtCheck(status: CheckStatus::UNKNOWN, httpStatus: 42, exists: false, sitemaps: []);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP status 42');
        }
    }

    public function acceptsHttpStatusZero(): void
    {
        $check = new RobotsTxtCheck(status: CheckStatus::UNKNOWN, httpStatus: 0, exists: false, sitemaps: []);

        Assert::same($check->httpStatus, 0);
    }

    public function acceptsBoundaryHttpStatus100(): void
    {
        $check = new RobotsTxtCheck(status: CheckStatus::OK, httpStatus: 100, exists: false, sitemaps: []);

        Assert::same($check->httpStatus, 100);
    }

    public function acceptsBoundaryHttpStatus599(): void
    {
        $check = new RobotsTxtCheck(status: CheckStatus::OK, httpStatus: 599, exists: false, sitemaps: []);

        Assert::same($check->httpStatus, 599);
    }

    public function serializesStatusEnumAndSitemaps(): void
    {
        $check = new RobotsTxtCheck(
            status: CheckStatus::OK,
            httpStatus: 200,
            exists: true,
            sitemaps: ['https://example.com/sitemap.xml'],
        );

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'ok',
                'httpStatus' => 200,
                'exists' => true,
                'sitemaps' => ['https://example.com/sitemap.xml'],
            ],
        );
    }
}
