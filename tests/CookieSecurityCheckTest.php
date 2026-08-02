<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\CookieSecurityCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CookieSecurityCheck::class)]
final class CookieSecurityCheckTest
{
    public function preservesAllFields(): void
    {
        $cookie = [
            'name' => 'session',
            'secure' => true,
            'httpOnly' => true,
            'sameSite' => 'Strict',
            'path' => '/',
            'domain' => null,
            'hostPrefixed' => false,
        ];

        $check = new CookieSecurityCheck(
            status: CheckStatus::WARNING,
            cookies: [$cookie],
            insecureCookieNames: ['tracker'],
            reason: '1 of 2 cookie(s) insecure: tracker',
        );

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->cookies, [$cookie]);
        Assert::same($check->insecureCookieNames, ['tracker']);
        Assert::same($check->reason, '1 of 2 cookie(s) insecure: tracker');
    }

    public function defaultsOptionalFieldsToEmptyOrNull(): void
    {
        $check = new CookieSecurityCheck(status: CheckStatus::OK);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->cookies, []);
        Assert::same($check->insecureCookieNames, []);
        Assert::null($check->reason);
    }

    public function serializesToArray(): void
    {
        $cookie = [
            'name' => 'sid',
            'secure' => false,
            'httpOnly' => false,
            'sameSite' => null,
            'path' => null,
            'domain' => 'example.com',
            'hostPrefixed' => false,
        ];

        $check = new CookieSecurityCheck(
            status: CheckStatus::WARNING,
            cookies: [$cookie],
            insecureCookieNames: ['sid'],
            reason: '1 of 1 cookie(s) insecure: sid',
        );

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'warning',
                'cookies' => [$cookie],
                'insecureCookieNames' => ['sid'],
                'reason' => '1 of 1 cookie(s) insecure: sid',
            ],
        );
    }

    public function roundtripsThroughJson(): void
    {
        $check = new CookieSecurityCheck(
            status: CheckStatus::OK,
            cookies: [],
            insecureCookieNames: [],
            reason: 'No Set-Cookie headers',
        );

        $decoded = \json_decode(\json_encode($check, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        Assert::same($decoded['status'], 'ok');
        Assert::same($decoded['cookies'], []);
        Assert::same($decoded['insecureCookieNames'], []);
        Assert::same($decoded['reason'], 'No Set-Cookie headers');
    }
}
