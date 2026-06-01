<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @api
 */
final readonly class SslCertificateService
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(string $host, ?string $expectedOrg = null): ?SslCertificate
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $stream = $this->openStream(host: $normalizedHost);

        if ($stream === null) {
            return null;
        }

        $params = \stream_context_get_params(context: $stream);
        \fclose(stream: $stream);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            $this->logger->error(
                message: 'No SSL certificate found',
                context: ['host' => $normalizedHost],
            );

            return null;
        }

        /** @var \OpenSSLCertificate $certificate */
        $certificate = $params['options']['ssl']['peer_certificate'];
        /** @var array<string, mixed>|false $certInfo */
        $certInfo = \openssl_x509_parse(certificate: $certificate);

        if ($certInfo === false || $certInfo === []) {
            $this->logger->error(
                message: 'Failed to parse SSL certificate',
                context: ['host' => $normalizedHost],
            );

            return null;
        }

        return $this->mapCertInfo(certInfo: $certInfo, expectedOrg: $expectedOrg);
    }

    /**
     * @param array<string, mixed> $certInfo
     */
    public function mapCertInfo(array $certInfo, ?string $expectedOrg = null): ?SslCertificate
    {
        if ($expectedOrg !== null && \trim(string: $expectedOrg) === '') {
            throw new \InvalidArgumentException(message: 'Expected organization must not be empty');
        }

        $validFromTimestamp = $this->readInteger(data: $certInfo, key: 'validFrom_time_t');
        $validUntilTimestamp = $this->readInteger(data: $certInfo, key: 'validTo_time_t');
        $subject = $this->readMap(data: $certInfo, key: 'subject');

        if ($validFromTimestamp === null || $validUntilTimestamp === null || $subject === null) {
            return null;
        }

        $subjectCn = $this->readString(data: $subject, key: 'CN');

        if ($subjectCn === null || $subjectCn === '') {
            return null;
        }

        $subjectOrg = $this->readString(data: $subject, key: 'O');

        if ($expectedOrg !== null) {
            $matchesOrg = ($subjectOrg !== null && \stripos(haystack: $subjectOrg, needle: $expectedOrg) !== false)
                || \stripos(haystack: $subjectCn, needle: $expectedOrg) !== false;

            if (!$matchesOrg) {
                return null;
            }
        }

        $issuer = $this->readMap(data: $certInfo, key: 'issuer');
        $issuerCn = $issuer === null ? null : $this->readString(data: $issuer, key: 'CN');

        return new SslCertificate(
            validFrom: (new DateTimeImmutable())->setTimestamp(timestamp: $validFromTimestamp),
            validUntil: (new DateTimeImmutable())->setTimestamp(timestamp: $validUntilTimestamp),
            subjectCn: $subjectCn,
            issuer: $issuerCn,
        );
    }

    /**
     * @return resource|null
     */
    private function openStream(string $host)
    {
        $errorMessage = null;
        $context = \stream_context_create(options: [
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        \set_error_handler(
            callback: static function (int $severity, string $message) use (&$errorMessage): bool {
                $errorMessage = $message;

                return true;
            },
        );

        try {
            $stream = \stream_socket_client(
                address: \sprintf('ssl://%s:443', $host),
                error_code: $errorCode,
                error_message: $systemErrorMessage,
                timeout: 30,
                context: $context,
            );
        } finally {
            \restore_error_handler();
        }

        if ($stream === false) {
            $this->logger->error(
                message: $errorMessage ?? $systemErrorMessage,
                context: ['host' => $host, 'code' => $errorCode],
            );

            return null;
        }

        return $stream;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readInteger(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }

        if (!\is_int($data[$key]) && !\is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>|null
     */
    private function readMap(array $data, string $key): ?array
    {
        if (!isset($data[$key]) || !\is_array($data[$key])) {
            return null;
        }

        return $data[$key];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function readString(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            return null;
        }

        return $data[$key];
    }
}
