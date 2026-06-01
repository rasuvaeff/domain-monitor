<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class SslCertificate
{
    public function __construct(
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validUntil,
        public string $subjectCn,
        public ?string $issuer = null,
    ) {
        if ($subjectCn === '') {
            throw new InvalidArgumentException(message: 'Subject CN must not be empty');
        }
    }

    public function daysUntilExpiry(?DateTimeImmutable $now = null): int
    {
        $current = $now ?? new DateTimeImmutable(datetime: 'now');

        return (int) \floor(($this->validUntil->getTimestamp() - $current->getTimestamp()) / 86400);
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $current = $now ?? new DateTimeImmutable(datetime: 'now');

        return $this->validUntil->getTimestamp() <= $current->getTimestamp();
    }

    public function isExpiringWithin(int $days, ?DateTimeImmutable $now = null): bool
    {
        if ($days < 0) {
            throw new InvalidArgumentException(message: 'Days must be greater than or equal to 0');
        }

        return $this->daysUntilExpiry(now: $now) <= $days;
    }
}
