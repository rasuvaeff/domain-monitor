<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Performs a TLS handshake against a host and reports the negotiated protocol
 * version and cipher suite. Flags deprecated protocols (TLS 1.0 / 1.1) and
 * weak ciphers (RC4, 3DES, NULL, EXPORT, MD5-only) following worst-wins:
 *
 * - `OK` — TLS 1.2+ negotiated, no weak cipher matched.
 * - `WARNING` — TLS 1.2+ with a weak cipher in use.
 * - `CRITICAL` — TLS 1.0 or TLS 1.1 negotiated, or handshake failed.
 * - `UNKNOWN` — the connection itself failed (no TLS metadata available).
 *
 * The service mirrors {@see SslCertificateService}: it reads the negotiated
 * parameters without validating the certificate chain (monitoring, not PKI).
 *
 * @api
 */
final readonly class TlsCipherService
{
    /**
     * Substrings matched (case-insensitive) against the negotiated cipher name.
     * Hits indicate a cipher that must not appear on a modern server.
     */
    public const array WEAK_CIPHER_PATTERNS = [
        'RC4',
        '3DES',
        'DES-CBC',
        'NULL',
        'EXPORT',
        'MD5',
        'RC2',
        'IDEA',
        'SEED',
        'ARIA',
        'GOST',
    ];

    /**
     * TLS versions considered insecure. Anything in this set maps to CRITICAL.
     */
    public const array DEPRECATED_PROTOCOLS = ['TLSv1', 'TLSv1.1', 'SSLv2', 'SSLv3'];

    private Closure $connector;

    public function __construct(
        ?callable $connector = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->connector = $connector === null
            ? $this->defaultConnector()
            : Closure::fromCallable(callback: $connector);
    }

    public function check(string $host, int $port = 443, float $timeoutSeconds = 10.0): TlsCipherCheck
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $connector = $this->connector;

        try {
            $meta = $connector($normalizedHost, $port, $timeoutSeconds);
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'TLS handshake failed',
                context: ['host' => $normalizedHost, 'port' => $port, 'error' => $exception->getMessage()],
            );

            return new TlsCipherCheck(
                status: CheckStatus::UNKNOWN,
                reason: \sprintf('TLS handshake failed: %s', $exception->getMessage()),
            );
        }

        if (!\is_array($meta)) {
            return new TlsCipherCheck(
                status: CheckStatus::CRITICAL,
                reason: 'TLS negotiation did not complete',
            );
        }

        $crypto = $meta['crypto'] ?? null;

        if (!\is_array($crypto) || $crypto === []) {
            return new TlsCipherCheck(
                status: CheckStatus::CRITICAL,
                reason: 'TLS negotiation did not complete',
            );
        }

        /** @var array<string, mixed> $crypto */
        $tlsVersion = $this->readString(data: $crypto, key: 'protocol');
        $rawCipher = $this->readString(data: $crypto, key: 'cipher_name');
        $cipherName = $rawCipher !== null && $rawCipher !== '' ? $rawCipher : null;
        $cipherVersion = $this->readString(data: $crypto, key: 'cipher_version');

        if ($tlsVersion === null || $cipherName === null) {
            return new TlsCipherCheck(
                status: CheckStatus::UNKNOWN,
                reason: 'TLS metadata missing protocol or cipher',
            );
        }

        $weakMatches = $this->matchWeakCiphers(cipher: $cipherName);
        $usesWeak = $weakMatches !== [];
        $deprecated = \in_array(needle: $tlsVersion, haystack: self::DEPRECATED_PROTOCOLS, strict: true);

        if ($deprecated) {
            return new TlsCipherCheck(
                status: CheckStatus::CRITICAL,
                tlsVersion: $tlsVersion,
                cipherName: $cipherName,
                cipherVersion: $cipherVersion,
                usesWeakCipher: $usesWeak,
                weakCipherNames: $weakMatches,
                reason: \sprintf('Deprecated protocol %s negotiated', $tlsVersion),
            );
        }

        if ($usesWeak) {
            return new TlsCipherCheck(
                status: CheckStatus::WARNING,
                tlsVersion: $tlsVersion,
                cipherName: $cipherName,
                cipherVersion: $cipherVersion,
                usesWeakCipher: true,
                weakCipherNames: $weakMatches,
                reason: \sprintf('Weak cipher %s negotiated', \implode(separator: ',', array: $weakMatches)),
            );
        }

        return new TlsCipherCheck(
            status: CheckStatus::OK,
            tlsVersion: $tlsVersion,
            cipherName: $cipherName,
            cipherVersion: $cipherVersion,
            usesWeakCipher: false,
            weakCipherNames: [],
            reason: \sprintf('%s with %s', $tlsVersion, $cipherName),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readString(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            return null;
        }

        return $data[$key];
    }

    /**
     * @return list<string>
     */
    private function matchWeakCiphers(string $cipher): array
    {
        $matches = [];

        foreach (self::WEAK_CIPHER_PATTERNS as $pattern) {
            if (\stripos(haystack: $cipher, needle: $pattern) !== false) {
                $matches[] = $pattern;
            }
        }

        return $matches;
    }

    private function defaultConnector(): Closure
    {
        return static function (string $host, int $port, float $timeout): array|false {
            $remote = \sprintf('tls://%s:%d', $host, $port);
            $context = \stream_context_create(options: [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            \set_error_handler(static fn(): bool => true);

            try {
                $stream = \stream_socket_client(
                    address: $remote,
                    error_code: $errorCode,
                    error_message: $errorMessage,
                    timeout: $timeout,
                    context: $context,
                );

                if ($stream === false) {
                    return false;
                }

                \stream_socket_enable_crypto($stream, true);
                $meta = \stream_get_meta_data(stream: $stream);
                \fclose(stream: $stream);

                return $meta;
            } finally {
                \restore_error_handler();
            }
        };
    }
}
