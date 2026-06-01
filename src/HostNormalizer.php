<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class HostNormalizer
{
    private const string HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';

    public function normalizeHost(string $hostOrUrl): string
    {
        $value = \trim(string: $hostOrUrl);

        if ($value === '') {
            throw new InvalidArgumentException(message: 'Host must not be empty');
        }

        $parseableValue = \str_contains(haystack: $value, needle: '://')
            ? $value
            : 'http://' . \ltrim(string: $value, characters: '/');

        $host = \parse_url(url: $parseableValue, component: \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            throw new InvalidArgumentException(message: \sprintf('Invalid host "%s"', $hostOrUrl));
        }

        $normalizedHost = $this->normalizeAsciiHost(host: $host);

        if (\preg_match(pattern: self::HOST_PATTERN, subject: $normalizedHost) !== 1) {
            throw new InvalidArgumentException(message: \sprintf('Invalid host "%s"', $hostOrUrl));
        }

        return $normalizedHost;
    }

    public function normalizeUrl(string $url): string
    {
        $value = \trim(string: $url);

        if ($value === '') {
            throw new InvalidArgumentException(message: 'URL must not be empty');
        }

        $parts = \parse_url(url: $value);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(message: \sprintf('Invalid URL "%s"', $url));
        }

        $scheme = \strtolower(string: $parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException(message: \sprintf('URL must use http or https scheme: "%s"', $url));
        }

        if ($parts['host'] === '') {
            throw new InvalidArgumentException(message: \sprintf('Invalid URL host in "%s"', $url));
        }

        $normalizedHost = $this->normalizeAsciiHost(host: $parts['host']);
        $authority = $normalizedHost;

        if (isset($parts['user']) && $parts['user'] !== '') {
            $authority = $parts['user'];
            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $authority .= ':' . $parts['pass'];
            }

            $authority .= '@' . $normalizedHost;
        }

        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $path = isset($parts['path']) && $parts['path'] !== ''
            ? $parts['path']
            : '/';

        $normalizedUrl = $scheme . '://' . $authority . $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalizedUrl .= '?' . $parts['query'];
        }

        return $normalizedUrl;
    }

    private function normalizeAsciiHost(string $host): string
    {
        $normalizedHost = \strtolower(string: \trim(string: $host, characters: ". \t\n\r\0\x0B"));

        if ($normalizedHost === '') {
            throw new InvalidArgumentException(message: 'Host must not be empty');
        }

        $idnFunction = 'idn_to_ascii';

        if (\function_exists(function: $idnFunction)) {
            /** @var string|false $idnHost */
            $idnHost = \call_user_func(
                $idnFunction,
                $normalizedHost,
                \defined('IDNA_DEFAULT') ? \constant('IDNA_DEFAULT') : 0,
                \defined('INTL_IDNA_VARIANT_UTS46') ? \constant('INTL_IDNA_VARIANT_UTS46') : 1,
            );

            if (\is_string($idnHost) && $idnHost !== '') {
                $normalizedHost = \strtolower(string: $idnHost);
            }
        }

        return $normalizedHost;
    }
}
