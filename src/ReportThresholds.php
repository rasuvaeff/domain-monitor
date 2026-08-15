<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @api
 */
final readonly class ReportThresholds implements JsonSerializable
{
    public function __construct(
        public ?int $sslWarnDays = 30,
        public int $whoisWarnDays = 30,
    ) {
        if ($sslWarnDays !== null && $sslWarnDays < 0) {
            throw new InvalidArgumentException(message: 'sslWarnDays must be greater than or equal to 0');
        }

        if ($whoisWarnDays < 0) {
            throw new InvalidArgumentException(message: 'whoisWarnDays must be greater than or equal to 0');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Pre-2.0 behaviour: no SSL expiry warning window (SSL is CRITICAL only
     * once expired), WHOIS warns within 30 days.
     */
    public static function legacy(): self
    {
        return new self(sslWarnDays: null);
    }

    public static function strict(): self
    {
        return new self(sslWarnDays: 14, whoisWarnDays: 30);
    }

    /**
     * @return array{sslWarnDays: int|null, whoisWarnDays: int}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'sslWarnDays' => $this->sslWarnDays,
            'whoisWarnDays' => $this->whoisWarnDays,
        ];
    }
}
