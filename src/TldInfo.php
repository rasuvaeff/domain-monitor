<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/**
 * @api
 */
final readonly class TldInfo implements JsonSerializable
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

    /**
     * @return array{domain: string, registrar: string|null, expirationDate: string|null, states: string[]}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'domain' => $this->domain,
            'registrar' => $this->registrar,
            'expirationDate' => $this->expirationDate?->format(format: DateTimeInterface::ATOM),
            'states' => $this->states,
        ];
    }
}
