<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface HttpProbeServiceInterface
{
    public function check(string $url, ?HttpProbeOptions $options = null): ProbeResult;

    public function probeWithResponse(string $url, ?HttpProbeOptions $options = null): HttpProbeWithResponse;
}
