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
        public ?int $sslWarnDays = null,
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
