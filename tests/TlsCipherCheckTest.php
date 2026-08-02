<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\TlsCipherCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TlsCipherCheck::class)]
final class TlsCipherCheckTest
{
    public function preservesAllFields(): void
    {
        $check = new TlsCipherCheck(
            status: CheckStatus::WARNING,
            tlsVersion: 'TLSv1.2',
            cipherName: 'ECDHE-RSA-RC4-SHA',
            cipherVersion: 'TLSv1.2',
            usesWeakCipher: true,
            weakCipherNames: ['RC4'],
            reason: 'Weak cipher RC4 negotiated',
        );

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->tlsVersion, 'TLSv1.2');
        Assert::same($check->cipherName, 'ECDHE-RSA-RC4-SHA');
        Assert::same($check->cipherVersion, 'TLSv1.2');
        Assert::true($check->usesWeakCipher);
        Assert::same($check->weakCipherNames, ['RC4']);
        Assert::same($check->reason, 'Weak cipher RC4 negotiated');
    }

    public function defaultsOptionalFieldsToFalseEmptyOrNull(): void
    {
        $check = new TlsCipherCheck(status: CheckStatus::UNKNOWN);

        Assert::same($check->status, CheckStatus::UNKNOWN);
        Assert::null($check->tlsVersion);
        Assert::null($check->cipherName);
        Assert::null($check->cipherVersion);
        Assert::false($check->usesWeakCipher);
        Assert::same($check->weakCipherNames, []);
        Assert::null($check->reason);
    }

    public function serializesToArray(): void
    {
        $check = new TlsCipherCheck(
            status: CheckStatus::OK,
            tlsVersion: 'TLSv1.3',
            cipherName: 'TLS_AES_256_GCM_SHA384',
            cipherVersion: 'TLSv1.3',
            usesWeakCipher: false,
            weakCipherNames: [],
            reason: 'TLSv1.3 with TLS_AES_256_GCM_SHA384',
        );

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'ok',
                'tlsVersion' => 'TLSv1.3',
                'cipherName' => 'TLS_AES_256_GCM_SHA384',
                'cipherVersion' => 'TLSv1.3',
                'usesWeakCipher' => false,
                'weakCipherNames' => [],
                'reason' => 'TLSv1.3 with TLS_AES_256_GCM_SHA384',
            ],
        );
    }

    public function roundtripsThroughJson(): void
    {
        $check = new TlsCipherCheck(
            status: CheckStatus::CRITICAL,
            tlsVersion: 'TLSv1.1',
            cipherName: 'ECDHE-RSA-AES128-SHA',
            cipherVersion: 'TLSv1.1',
            usesWeakCipher: false,
            weakCipherNames: [],
            reason: 'Deprecated protocol TLSv1.1 negotiated',
        );

        $decoded = \json_decode(\json_encode($check, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        Assert::same($decoded['status'], 'critical');
        Assert::same($decoded['tlsVersion'], 'TLSv1.1');
        Assert::false($decoded['usesWeakCipher']);
    }
}
