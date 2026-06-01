<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class PortCheck
{
    public function __construct(
        public CheckStatus $status,
        public string $host,
        public int $port,
        public float $connectTime,
        public ?string $error = null,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(message: \sprintf('Invalid port %d', $port));
        }

        if ($connectTime < 0) {
            throw new InvalidArgumentException(message: 'Connect time must be greater than or equal to 0');
        }
    }
}
