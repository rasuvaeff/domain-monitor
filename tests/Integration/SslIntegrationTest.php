<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use Rasuvaeff\DomainMonitor\SslCertificateService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class SslIntegrationTest
{
    #[BeforeTest]
    public function setUp(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }
    }

    public function loadsRemoteCertificate(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $certificate = (new SslCertificateService())->check(host: 'google.com');

        Assert::notNull($certificate);
        Assert::notSame($certificate->subjectCn, '');
    }
}
