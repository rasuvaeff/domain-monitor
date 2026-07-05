<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @api
 */
final readonly class PortCheck implements JsonSerializable
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

    /**
     * @return array{status: string, host: string, port: int, connectTime: float, error: string|null}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'host' => $this->host,
            'port' => $this->port,
            'connectTime' => $this->connectTime,
            'error' => $this->error,
        ];
    }
}
