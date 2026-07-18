<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * A single per-check status change between two {@see DomainHealthReport} snapshots.
 *
 * @api
 */
final readonly class StatusTransition implements JsonSerializable
{
    public function __construct(
        public CheckName $check,
        public ?CheckStatus $from,
        public ?CheckStatus $to,
        public TransitionKind $kind,
    ) {}

    /**
     * @return array{check: string, from: string|null, to: string|null, kind: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'check' => $this->check->value,
            'from' => $this->from?->value,
            'to' => $this->to?->value,
            'kind' => $this->kind->value,
        ];
    }
}
