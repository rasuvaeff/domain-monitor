<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface SitemapServiceInterface
{
    public function check(string $sitemapUrl, ?HttpProbeOptions $options = null): SitemapCheck;
}
