<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\SslCertificateService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SslCertificateService::class)]
final class SslCertificateServiceTest
{
    private SslCertificateService $service;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->service = new SslCertificateService();
    }

    public function mapsCertificateInfo(): void
    {
        $certificate = $this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com', 'O' => 'Example Inc'],
            'issuer' => ['CN' => 'Example CA'],
        ]);

        Assert::notNull($certificate);
        Assert::same($certificate->subjectCn, 'example.com');
        Assert::same($certificate->issuer, 'Example CA');
        Assert::same($certificate->validFrom->getTimestamp(), 1_767_225_600);
        Assert::same($certificate->validUntil->getTimestamp(), 1_769_817_600);
    }

    public function acceptsNumericStringTimestamps(): void
    {
        $certificate = $this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => '1767225600',
            'validTo_time_t' => '1769817600',
            'subject' => ['CN' => 'example.com'],
        ]);

        Assert::notNull($certificate);
        Assert::same($certificate->validFrom->getTimestamp(), 1_767_225_600);
    }

    public function defaultsIssuerToNullWhenAbsentOrWithoutCn(): void
    {
        $withoutIssuer = $this->service->mapCertInfo(certInfo: $this->baseCertInfo());
        $issuerWithoutCn = $this->service->mapCertInfo(certInfo: [...$this->baseCertInfo(), 'issuer' => ['O' => 'Example CA']]);

        Assert::notNull($withoutIssuer);
        Assert::null($withoutIssuer->issuer);
        Assert::notNull($issuerWithoutCn);
        Assert::null($issuerWithoutCn->issuer);
    }

    public function returnsNullWhenValidFromTimestampMissing(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    public function returnsNullWhenValidToTimestampMissing(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    public function returnsNullWhenTimestampIsNotNumeric(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 'not-a-number',
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 'example.com'],
        ]));
    }

    public function returnsNullWhenSubjectIsNotArray(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => 'example.com',
        ]));
    }

    public function returnsNullWhenCnMissing(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['O' => 'Example Inc'],
        ]));
    }

    public function returnsNullWhenCnIsEmpty(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => ''],
        ]));
    }

    public function matchesExpectedOrganizationInSubjectO(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'example.com', 'O' => 'Example Inc']],
            expectedOrg: 'Example',
        );

        Assert::notNull($certificate);
    }

    public function matchesExpectedOrganizationInSubjectCnWhenNoOrg(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'secure.example.com']],
            expectedOrg: 'example',
        );

        Assert::notNull($certificate);
    }

    public function returnsNullWhenExpectedOrganizationDoesNotMatch(): void
    {
        $certificate = $this->service->mapCertInfo(
            certInfo: [...$this->baseCertInfo(), 'subject' => ['CN' => 'example.com', 'O' => 'Example Inc']],
            expectedOrg: 'Microsoft',
        );

        Assert::null($certificate);
    }

    public function returnsNullWhenSubjectCnIsNotString(): void
    {
        Assert::null($this->service->mapCertInfo(certInfo: [
            'validFrom_time_t' => 1_767_225_600,
            'validTo_time_t' => 1_769_817_600,
            'subject' => ['CN' => 123],
        ]));
    }

    public function throwsOnEmptyExpectedOrganization(): void
    {
        try {
            $this->service->mapCertInfo(certInfo: $this->baseCertInfo(), expectedOrg: '  ');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Expected organization must not be empty');
        }
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
