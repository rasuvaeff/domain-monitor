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
}
