<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class CheckResult implements JsonSerializable
{
    public function __construct(
        public CheckName $check,
        public CheckStatus $status,
        public string $reason,
    ) {}

    /**
     * @return array{check: string, status: string, reason: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'check' => $this->check->value,
            'status' => $this->status->value,
            'reason' => $this->reason,
        ];
    }
}
