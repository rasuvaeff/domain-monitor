<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class HttpContentCheck
{
    public function __construct(
        public CheckStatus $status,
        public int $httpStatus,
        public ?string $finalUrl,
        public bool $requiredTextFound,
        public bool $forbiddenTextFound,
    ) {
        if ($httpStatus !== 0 && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $httpStatus));
        }
    }
}
