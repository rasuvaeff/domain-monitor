<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\SslCertificateService;

#[CoversNothing]
final class SslIntegrationTest extends TestCase
{
    #[Test]
    public function loadsRemoteCertificate(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            $this->markTestSkipped(message: 'Set DOMAIN_MONITOR_NET=1 to run network integration tests');
        }

        $certificate = (new SslCertificateService())->check(host: 'google.com');

        $this->assertNotNull($certificate);
        $this->assertNotSame('', $certificate->subjectCn);
    }
}
