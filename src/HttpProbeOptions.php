<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class HttpProbeOptions
{
    private const string METHOD_PATTERN = '/^[A-Z]+$/';
    private const string DEFAULT_USER_AGENT = 'rasuvaeff/domain-monitor';

    public string $method;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method = 'GET',
        public array $headers = [],
        public float $timeoutSeconds = 5.0,
        public string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
        $this->method = \strtoupper($method);

        if (\preg_match(pattern: self::METHOD_PATTERN, subject: $this->method) !== 1) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP method "%s"', $method));
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException(message: 'Timeout must be greater than 0');
        }

        foreach (\array_keys($headers) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(message: 'Header names must be non-empty strings');
            }
        }

        if ($userAgent === '') {
            throw new InvalidArgumentException(message: 'User-Agent must not be empty');
        }
    }
}
