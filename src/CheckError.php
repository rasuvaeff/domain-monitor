<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class CheckError implements JsonSerializable
{
    public function __construct(
        public CheckName $check,
        public string $message,
    ) {}

    /**
     * @return array{check: string, message: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'check' => $this->check->value,
            'message' => $this->message,
        ];
    }
}
