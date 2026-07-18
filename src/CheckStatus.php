<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
enum CheckStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
    case UNKNOWN = 'unknown';

    /**
     * Aggregate ordering (worst-wins). `UNKNOWN` is lowest so a check that could
     * not be evaluated never inflates the report's aggregate status.
     */
    public function severity(): int
    {
        return match ($this) {
            self::UNKNOWN => 0,
            self::OK => 1,
            self::WARNING => 2,
            self::CRITICAL => 3,
        };
    }
}
