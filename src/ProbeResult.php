<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class ProbeResult
{
    public function __construct(
        public int $status,
        public float $totalTime,
    ) {
        if ($status !== 0 && ($status < 100 || $status > 599)) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $status));
        }

        if ($totalTime < 0) {
            throw new InvalidArgumentException(message: 'Total time must be greater than or equal to 0');
        }
    }
}
