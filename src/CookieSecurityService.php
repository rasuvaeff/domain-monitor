<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Message\ResponseInterface;

/**
 * Audits the security attributes of every `Set-Cookie` header on a PSR-7
 * response. A cookie is flagged insecure if **any** of these holds:
 *
 * - missing `Secure` (cookie sent over plain HTTP);
 * - missing `HttpOnly` (cookie readable from JavaScript);
 * - `__Host-` prefix without `Secure`, `HttpOnly`, `Path=/` and no `Domain`;
 * - `__Secure-` prefix without `Secure`.
 *
 * Status follows worst-wins: `OK` when no cookies are flagged, `WARNING`
 * otherwise. A response with no `Set-Cookie` headers is `OK` (nothing to audit).
 *
 * Pass the same {@see ResponseInterface} reused from {@see HttpProbeService}
 * — no extra network round-trip.
 *
 * @api
 */
final readonly class CookieSecurityService
{
    public function check(ResponseInterface $response): CookieSecurityCheck
    {
        $rawHeaders = $response->getHeader('Set-Cookie');
        $cookies = [];
        $insecure = [];

        foreach ($rawHeaders as $header) {
            $cookie = $this->parse(header: $header);

            if ($cookie === null) {
                continue;
            }

            $cookies[] = $cookie;

            if (!$this->isSecure(parsed: $cookie)) {
                $insecure[] = $cookie['name'];
            }
        }

        if ($cookies === []) {
            return new CookieSecurityCheck(
                status: CheckStatus::OK,
                reason: 'No Set-Cookie headers',
            );
        }

        if ($insecure === []) {
            return new CookieSecurityCheck(
                status: CheckStatus::OK,
                cookies: $cookies,
                reason: \sprintf('%d cookie(s) all secure', \count($cookies)),
            );
        }

        return new CookieSecurityCheck(
            status: CheckStatus::WARNING,
            cookies: $cookies,
            insecureCookieNames: $insecure,
            reason: \sprintf('%d of %d cookie(s) insecure: %s', \count($insecure), \count($cookies), \implode(separator: ', ', array: $insecure)),
        );
    }

    /**
     * @return ?array{name: string, secure: bool, httpOnly: bool, sameSite: ?string, path: ?string, domain: ?string, hostPrefixed: bool}
     */
    private function parse(string $header): ?array
    {
        $parts = \array_map('trim', \explode(separator: ';', string: $header));
        $firstPart = $parts[0] ?? '';

        if ($firstPart === '') {
            return null;
        }

        $nameValue = \explode(separator: '=', string: $firstPart, limit: 2);
        $name = \trim(string: $nameValue[0] ?? '');

        if ($name === '') {
            return null;
        }

        $secure = false;
        $httpOnly = false;
        $sameSite = null;
        $path = null;
        $domain = null;

        foreach (\array_slice(array: $parts, offset: 1) as $attribute) {
            $lower = \strtolower(string: $attribute);
            $equals = \strpos(haystack: $attribute, needle: '=');

            if ($lower === 'secure') {
                $secure = true;

                continue;
            }

            if ($lower === 'httponly') {
                $httpOnly = true;

                continue;
            }

            if ($equals === false) {
                continue;
            }

            $attrName = \strtolower(string: \trim(string: \substr(string: $attribute, offset: 0, length: $equals)));
            $attrValue = \trim(string: \substr(string: $attribute, offset: $equals + 1));

            if ($attrName === 'samesite' && $attrValue !== '') {
                $sameSite = $attrValue;
            } elseif ($attrName === 'path') {
                $path = $attrValue;
            } elseif ($attrName === 'domain') {
                $domain = $attrValue;
            }
        }

        return [
            'name' => $name,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
            'path' => $path,
            'domain' => $domain,
            'hostPrefixed' => \str_starts_with(haystack: $name, needle: '__Host-'),
        ];
    }

    /**
     * @param array{name: string, secure: bool, httpOnly: bool, sameSite: ?string, path: ?string, domain: ?string, hostPrefixed: bool} $parsed
     */
    private function isSecure(array $parsed): bool
    {
        if (!$parsed['secure'] || !$parsed['httpOnly']) {
            return false;
        }

        if ($parsed['hostPrefixed']) {
            return $parsed['path'] === '/' && $parsed['domain'] === null;
        }

        return true;
    }
}
