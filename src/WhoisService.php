<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use DateTimeImmutable;
use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Modules\Tld\TldInfo as VendorTldInfo;
use Iodev\Whois\Whois;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @api
 */
final readonly class WhoisService
{
    public function __construct(
        private Whois $whois,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(string $host): ?TldInfo
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);

        try {
            $vendorInfo = $this->whois->loadDomainInfo(domain: $normalizedHost);

            if (!$vendorInfo instanceof VendorTldInfo) {
                $fallbackDomain = $this->extractFallbackDomain(host: $normalizedHost);

                if ($fallbackDomain === null) {
                    return null;
                }

                $vendorInfo = $this->whois->loadDomainInfo(domain: $fallbackDomain);

                if (!$vendorInfo instanceof VendorTldInfo) {
                    return null;
                }
            }

            return $this->mapVendorInfo(vendorInfo: $vendorInfo);
        } catch (ConnectionException|ServerMismatchException|WhoisException $exception) {
            $this->logger->error(
                message: $exception->getMessage(),
                context: ['host' => $normalizedHost],
            );
        }

        return null;
    }

    private function mapVendorInfo(VendorTldInfo $vendorInfo): TldInfo
    {
        /** @var array<string, mixed> $properties */
        $properties = $vendorInfo->toArray();
        $domain = $this->readArrayString(data: $properties, key: 'domainName') ?? '';
        $registrar = $this->readArrayString(data: $properties, key: 'registrar');
        $registrar = $registrar === '' ? null : $registrar;
        $expirationTimestamp = $this->readArrayInt(data: $properties, key: 'expirationDate');
        $expirationTimestamp = $expirationTimestamp !== null && $expirationTimestamp > 0
            ? $expirationTimestamp
            : null;
        $statesValue = $this->readArrayList(data: $properties, key: 'states')
            ?? $this->readArrayList(data: $properties, key: 'status')
            ?? $this->readArrayList(data: $properties, key: 'statuses')
            ?? [];

        $states = [];

        /** @var mixed $state */
        foreach ($statesValue as $state) {
            if (\is_string($state) && $state !== '') {
                $states[] = $state;
            }
        }

        return new TldInfo(
            domain: $domain,
            registrar: $registrar,
            expirationDate: $expirationTimestamp === null
                ? null
                : (new DateTimeImmutable())->setTimestamp(timestamp: $expirationTimestamp),
            states: $states,
        );
    }

    private function extractFallbackDomain(string $host): ?string
    {
        $parts = \explode(separator: '.', string: $host);

        if (\count($parts) < 3) {
            return null;
        }

        return \implode(separator: '.', array: \array_slice(array: $parts, offset: -2));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readArrayString(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            return null;
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readArrayInt(array $data, string $key): ?int
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
     * @return list<mixed>|null
     */
    private function readArrayList(array $data, string $key): ?array
    {
        if (!isset($data[$key]) || !\is_array($data[$key])) {
            return null;
        }

        return \array_values($data[$key]);
    }
}
