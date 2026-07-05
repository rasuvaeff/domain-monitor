<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class DomainMonitorOptions
{
    private const string METHOD_PATTERN = '/^[A-Z]+$/';
    private const string DEFAULT_USER_AGENT = 'rasuvaeff/domain-monitor';

    public string $httpMethod;

    public function __construct(
        public int $port = 443,
        public float $timeoutSeconds = 10.0,
        public string $userAgent = self::DEFAULT_USER_AGENT,
        string $httpMethod = 'GET',
        public ?string $expectedOrg = null,
        public int $expectedStatus = 200,
        public ?string $requiredText = null,
        public ?string $forbiddenText = null,
        public ?ReportThresholds $thresholds = null,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(message: \sprintf('Invalid port %d', $port));
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException(message: 'Timeout must be greater than 0');
        }

        if ($userAgent === '') {
            throw new InvalidArgumentException(message: 'User-Agent must not be empty');
        }

        $this->httpMethod = \strtoupper($httpMethod);

        if (\preg_match(pattern: self::METHOD_PATTERN, subject: $this->httpMethod) !== 1) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP method "%s"', $httpMethod));
        }

        if ($expectedStatus < 100 || $expectedStatus > 599) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $expectedStatus));
        }

        if ($expectedOrg !== null && \trim(string: $expectedOrg) === '') {
            throw new InvalidArgumentException(message: 'Expected organization must not be empty');
        }
    }
}
