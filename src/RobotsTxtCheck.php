<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class RobotsTxtCheck
{
    /**
     * @param string[] $sitemaps
     */
    public function __construct(
        public CheckStatus $status,
        public int $httpStatus,
        public bool $exists,
        public array $sitemaps,
    ) {
        if ($httpStatus !== 0 && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $httpStatus));
        }
    }
}
