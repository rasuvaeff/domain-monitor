<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Closure;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\TlsCipherService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TlsCipherService::class)]
final class TlsCipherServiceTest
{
    public function normalizesHostBeforeLookup(): void
    {
        $calls = [];

        $connector = static function (string $host, int $port, float $timeout) use (&$calls): array {
            $calls[] = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

            return ['crypto' => ['protocol' => 'TLSv1.3', 'cipher_name' => 'TLS_AES_256_GCM_SHA384', 'cipher_version' => 'TLSv1.3']];
        };

        (new TlsCipherService(connector: $connector))->check(host: 'HTTPS://Example.COM:8443/Path');

        Assert::same($calls, [['host' => 'example.com', 'port' => 443, 'timeout' => 10.0]]);
    }

    public function returnsOkForTls13WithModernCipher(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1.3',
            'cipher_name' => 'TLS_AES_256_GCM_SHA384',
            'cipher_version' => 'TLSv1.3',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->tlsVersion, 'TLSv1.3');
        Assert::same($check->cipherName, 'TLS_AES_256_GCM_SHA384');
        Assert::same($check->cipherVersion, 'TLSv1.3');
        Assert::false($check->usesWeakCipher);
        Assert::same($check->weakCipherNames, []);
    }

    public function returnsCriticalForTls10(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1',
            'cipher_name' => 'ECDHE-RSA-AES256-SHA',
            'cipher_version' => 'TLSv1',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
        Assert::same($check->tlsVersion, 'TLSv1');
        Assert::same($check->weakCipherNames, []);
    }

    public function returnsCriticalForTls11(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1.1',
            'cipher_name' => 'ECDHE-RSA-AES256-SHA',
            'cipher_version' => 'TLSv1.1',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
    }

    public function returnsCriticalForSslv3(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'SSLv3',
            'cipher_name' => 'RC4-SHA',
            'cipher_version' => 'SSLv3',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
    }

    public function returnsWarningWhenModernProtocolWithWeakCipher(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1.2',
            'cipher_name' => 'ECDHE-RSA-RC4-SHA',
            'cipher_version' => 'TLSv1.2',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::same($check->tlsVersion, 'TLSv1.2');
        Assert::true($check->usesWeakCipher);
        Assert::same($check->weakCipherNames, ['RC4']);
    }

    public function detectsMultipleWeakCipherPatterns(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1.2',
            'cipher_name' => 'EXP-RC4-MD5',
            'cipher_version' => 'TLSv1.2',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::true($check->usesWeakCipher);
        Assert::same($check->weakCipherNames, ['RC4', 'MD5']);
    }

    public function detects3DesCipher(): void
    {
        $connector = $this->connector(['crypto' => [
            'protocol' => 'TLSv1.2',
            'cipher_name' => 'ECDHE-RSA-3DES-EDE-CBC-SHA',
            'cipher_version' => 'TLSv1.2',
        ]]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->weakCipherNames, ['3DES']);
    }

    public function returnsCriticalWhenHandshakeIncomplete(): void
    {
        $connector = $this->connector(['crypto' => []]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
        Assert::null($check->tlsVersion);
        Assert::null($check->cipherName);
    }

    public function returnsUnknownWhenConnectorThrows(): void
    {
        $connector = static function (string $host, int $port, float $timeout): array {
            unset($host, $port, $timeout);

            throw new \RuntimeException(message: 'Connection refused');
        };

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::UNKNOWN);
        Assert::string($check->reason)->contains('TLS handshake failed');
    }

    public function returnsUnknownWhenConnectorReturnsFalse(): void
    {
        $connector = static fn(string $host, int $port, float $timeout): array|false => false;

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
    }

    public function returnsUnknownWhenCryptoMetadataMissingProtocol(): void
    {
        $connector = $this->connector(['crypto' => ['cipher_name' => 'AES256-SHA']]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::UNKNOWN);
    }

    public function returnsUnknownWhenCryptoMetadataMissingCipherName(): void
    {
        $connector = $this->connector(['crypto' => ['protocol' => 'TLSv1.3']]);

        $check = (new TlsCipherService(connector: $connector))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::UNKNOWN);
    }

    public function passesPortAndTimeoutToConnector(): void
    {
        $received = [];

        $connector = static function (string $host, int $port, float $timeout) use (&$received): array {
            $received = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

            return ['crypto' => ['protocol' => 'TLSv1.3', 'cipher_name' => 'TLS_AES_128_GCM_SHA256']];
        };

        (new TlsCipherService(connector: $connector))->check(host: 'example.com', port: 8443, timeoutSeconds: 5.0);

        Assert::same($received, ['host' => 'example.com', 'port' => 8443, 'timeout' => 5.0]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function connector(array $meta): Closure
    {
        return static fn(string $host, int $port, float $timeout): array => $meta;
    }
}
