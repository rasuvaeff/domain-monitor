<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
enum TransitionKind: string
{
    /** A check that was absent in the previous report is now present. */
    case Appeared = 'appeared';

    /** A check that was present in the previous report is now absent. */
    case Disappeared = 'disappeared';

    /** The check's status got worse (higher severity). */
    case Degraded = 'degraded';

    /** The check's status got better (lower severity). */
    case Recovered = 'recovered';

    /** The status changed to or from `UNKNOWN`, where severity is not comparable. */
    case Changed = 'changed';
}
