<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @api
 */
final readonly class ProbeResult implements JsonSerializable
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

    /**
     * @return array{status: int, totalTime: float}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'totalTime' => $this->totalTime,
        ];
    }
}
