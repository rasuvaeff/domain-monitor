<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\SslCertificateService;

#[CoversClass(SslCertificateService::class)]
final class SslCertificateServiceTest extends TestCase
{
    private SslCertificateService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = new SslCertificateService();
    }

    #[Test]
    public function mapsCertificateInfo(): void
    {
        $certificate = $this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com', 'O' => 'Example Inc'],
            'issuer' => ['CN' => 'Example CA'],
        ]);

        $this->assertNotNull($certificate);
        $this->assertSame('example.com', $certificate->subjectCn);
        $this->assertSame('Example CA', $certificate->issuer);
        $this->assertSame(1_767_225_600, $certificate->validFrom->getTimestamp());
        $this->assertSame(1_769_817_600, $certificate->validUntil->getTimestamp());
    }

    #[Test]
    public function acceptsNumericStringTimestamps(): void
    {
        $certificate = $this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => '1767225600',
            'validTo_time_t' => '1769817600',
            'subject' => ['CN' => 'example.com'],
        ]);

        $this->assertNotNull($certificate);
        $this->assertSame(1_767_225_600, $certificate->validFrom->getTimestamp());
    }

    #[Test]
    public function defaultsIssuerToNullWhenAbsentOrWithoutCn(): void
    {
        $withoutIssuer = $this->service->mapCertInfo(certInfo: $this->baseCertInfo());
        $issuerWithoutCn = $this->service->mapCertInfo(certInfo: [...$this->baseCertInfo(), 'issuer' => ['O' => 'Example CA']]);

        $this->assertNotNull($withoutIssuer);
        $this->assertNull($withoutIssuer->issuer);
        $this->assertNotNull($issuerWithoutCn);
        $this->assertNull($issuerWithoutCn->issuer);
    }

    #[Test]
    public function returnsNullWhenValidFromTimestampMissing(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    #[Test]
    public function returnsNullWhenValidToTimestampMissing(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    #[Test]
    public function returnsNullWhenTimestampIsNotNumeric(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 'not-a-number',
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    #[Test]
    public function returnsNullWhenSubjectIsNotArray(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => 'example.com',
        ]));
    }

    #[Test]
    public function returnsNullWhenCnMissing(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['O' => 'Example Inc'],
        ]));
    }

    #[Test]
    public function returnsNullWhenCnIsEmpty(): void
    {
        $this->assertNull($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => ''],
        ]));
    }

    #[Test]
    public function matchesExpectedOrganizationInSubjectO(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'example.com', 'O' => 'Example Inc']],
            expectedOrg: 'Example',
        );

        $this->assertNotNull($certificate);
    }

    #[Test]
    public function matchesExpectedOrganizationInSubjectCnWhenNoOrg(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'secure.example.com']],
            expectedOrg: 'example',
        );

        $this->assertNotNull($certificate);
    }

    #[Test]
    public function returnsNullWhenExpectedOrganizationDoesNotMatch(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'example.com', 'O' => 'Example Inc']],
            expectedOrg: 'Microsoft',
        );

        $this->assertNull($certificate);
    }

    #[Test]
    public function throwsOnEmptyExpectedOrganization(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Expected organization must not be empty');

        $this->service->mapCertInfo(certInfo: $this->baseCertInfo(), expectedOrg: '  ');
    }

    /**
     * @return array<string, mixed>
     */
    private function baseCertInfo(): array
    {
        return [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com'],
        ];
    }
}
