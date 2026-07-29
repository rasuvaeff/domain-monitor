<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\CookieSecurityService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CookieSecurityService::class)]
final class CookieSecurityServiceTest
{
    public function returnsOkWhenNoSetCookieHeaders(): void
    {
        $check = (new CookieSecurityService())->check(response: $this->response([]));

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->cookies, []);
        Assert::same($check->insecureCookieNames, []);
    }

    public function returnsOkWhenAllCookiesAreSecureHttpOnly(): void
    {
        $response = $this->response([
            'session=abc; Secure; HttpOnly; Path=/',
            'tracker=xyz; Secure; HttpOnly; SameSite=Strict',
        ]);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same(count($check->cookies), 2);
        Assert::same($check->insecureCookieNames, []);
    }

    public function flagsCookieMissingSecure(): void
    {
        $response = $this->response(['session=abc; HttpOnly; Path=/']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->insecureCookieNames, ['session']);
    }

    public function flagsCookieMissingHttpOnly(): void
    {
        $response = $this->response(['session=abc; Secure; Path=/']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->insecureCookieNames, ['session']);
    }

    public function flagsHostPrefixedCookieWithoutPathRoot(): void
    {
        $response = $this->response(['__Host-csrf=token; Secure; HttpOnly; Path=/app']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->insecureCookieNames, ['__Host-csrf']);
    }

    public function flagsHostPrefixedCookieWithDomain(): void
    {
        $response = $this->response(['__Host-csrf=token; Secure; HttpOnly; Path=/; Domain=example.com']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->insecureCookieNames, ['__Host-csrf']);
    }

    public function acceptsHostPrefixedCookieWithRootPath(): void
    {
        $response = $this->response(['__Host-csrf=token; Secure; HttpOnly; Path=/']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->insecureCookieNames, []);
    }

    public function parsesSameSiteAttribute(): void
    {
        $response = $this->response(['session=abc; Secure; HttpOnly; SameSite=None']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->cookies[0]['sameSite'], 'None');
    }

    public function ignoresEmptyHeaderString(): void
    {
        $response = $this->response(['   ', '']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->cookies, []);
    }

    public function aggregatesMultipleInsecureCookies(): void
    {
        $response = $this->response([
            'a=1; HttpOnly',
            'b=2; Secure',
            'c=3; Secure; HttpOnly',
        ]);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->insecureCookieNames, ['a', 'b']);
    }

    public function reportsCountsInReason(): void
    {
        $response = $this->response(['a=1; HttpOnly']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::string($check->reason)->contains('1 of 1 cookie(s) insecure: a');
    }

    public function reportsNoCookiesReasonWhenHeadersEmpty(): void
    {
        $response = $this->response([]);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::string($check->reason)->contains('No Set-Cookie headers');
    }

    public function skipsInvalidHeadersButKeepsValidOnesInSameResponse(): void
    {
        $response = $this->response([
            '   ',
            'session=abc; Secure; HttpOnly',
            '',
        ]);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same(\count($check->cookies), 1);
        Assert::same($check->cookies[0]['name'], 'session');
    }

    public function parsesNameFromFirstPart(): void
    {
        $response = $this->response(['session=abc; Secure; HttpOnly']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->cookies[0]['name'], 'session');
    }

    public function parsesSameSiteValueWithWhitespaceAroundEquals(): void
    {
        $response = $this->response(['session=abc; Secure; HttpOnly; SameSite = Strict']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->cookies[0]['sameSite'], 'Strict');
    }

    public function parsesPathAndDomainAttributes(): void
    {
        $response = $this->response(['session=abc; Secure; HttpOnly; Path=/app; Domain=example.com']);

        $check = (new CookieSecurityService())->check(response: $response);

        Assert::same($check->cookies[0]['path'], '/app');
        Assert::same($check->cookies[0]['domain'], 'example.com');
    }

    /**
     * @param list<string> $setCookie
     */
    private function response(array $setCookie): ResponseInterface
    {
        $response = new Response(status: 200);

        if ($setCookie === []) {
            return $response;
        }

        return $response->withHeader('Set-Cookie', $setCookie);
    }
}
