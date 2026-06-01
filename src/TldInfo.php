<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use DateTimeImmutable;

/**
 * @api
 */
final readonly class TldInfo
{
    /**
     * @param string[] $states
     */
    public function __construct(
        public string $domain,
        public ?string $registrar = null,
        public ?DateTimeImmutable $expirationDate = null,
        public array $states = [],
    ) {}

    public function daysUntilExpiry(?DateTimeImmutable $now = null): ?int
    {
        if ($this->expirationDate === null) {
            return null;
        }

        $current = $now ?? new DateTimeImmutable(datetime: 'now');

        return (int) \floor(($this->expirationDate->getTimestamp() - $current->getTimestamp()) / 86400);
    }
}
