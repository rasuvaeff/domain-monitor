<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface SslCertificateServiceInterface
{
    public function check(string $host, ?string $expectedOrg = null): ?SslCertificate;
}
