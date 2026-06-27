<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Modules\Tld\TldInfo as VendorTldInfo;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeWhois;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Rasuvaeff\DomainMonitor\WhoisService;
use ReflectionClass;
use ReflectionProperty;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Throwable;

#[Test]
#[Covers(WhoisService::class)]
final class WhoisServiceTest
{
    public function mapsAllVendorFields(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'registrar' => 'Example Registrar',
            'expirationDate' => 1_769_817_600,
            'states' => ['active', 'ok'],
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->domain, 'example.com');
        Assert::same($result->registrar, 'Example Registrar');
        Assert::notNull($result->expirationDate);
        Assert::same($result->expirationDate->getTimestamp(), 1_769_817_600);
        Assert::same($result->states, ['active', 'ok']);
    }

    public function defaultsMissingOptionalFields(): void
    {
        $vendorInfo = $this->createVendorInfo(['domainName' => 'example.com']);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->domain, 'example.com');
        Assert::null($result->registrar);
        Assert::null($result->expirationDate);
        Assert::same($result->states, []);
    }

    public function ignoresNonStringStateEntries(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'states' => ['ok', '', 42, 'clientHold'],
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->states, ['ok', 'clientHold']);
    }

    public function returnsNullWhenLookupAndFallbackBothFail(): void
    {
        $whois = $this->fakeWhoisReturning(null);

        $result = (new WhoisService(whois: $whois))->check(host: 'www.example.com');

        Assert::null($result);
        Assert::same($whois->callCount(), 2);
    }

    public function retriesWithBaseDomainWhenSubdomainLookupFails(): void
    {
        $vendorInfo = $this->createVendorInfo(['domainName' => 'example.com']);
        $whois = new FakeWhois(static fn(string $domain): ?VendorTldInfo => $domain === 'example.com' ? $vendorInfo : null);

        $result = (new WhoisService(whois: $whois))->check(host: 'a.b.example.com');

        Assert::notNull($result);
        Assert::same($result->domain, 'example.com');
        Assert::same($whois->callCount(), 2);
    }

    public function usesStatesKeyWhenPresent(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'states' => ['active'],
            'status' => ['wrong'],
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->states, ['active']);
    }

    public function usesStatesKeyFromVendorInfo(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'states' => ['active'],
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->states, ['active']);
    }

    public function setsRegistrarToNullWhenEmpty(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'registrar' => '',
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::null($result->registrar);
    }

    public function ignoresZeroAndNegativeExpirationTimestamps(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'expirationDate' => 0,
        ]);
        $whois = $this->fakeWhoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::null($result->expirationDate);
    }

    public function returnsNullForShortHostWithoutFallback(): void
    {
        $whois = $this->fakeWhoisReturning(null);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::null($result);
        Assert::same($whois->callCount(), 1);
    }

    #[DataProvider('caughtExceptionProvider')]
    public function returnsNullAndLogsOnWhoisException(Throwable $exception): void
    {
        $whois = new FakeWhois(static fn(): ?VendorTldInfo => null, $exception);
        $logger = new RecordingLogger();

        $result = (new WhoisService(whois: $whois, logger: $logger))->check(host: 'example.com');

        Assert::null($result);
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'boom');
        Assert::same($logger->records[0]['context'], ['host' => 'example.com']);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function caughtExceptionProvider(): iterable
    {
        yield 'connection' => [new ConnectionException(message: 'boom')];
        yield 'server mismatch' => [new ServerMismatchException(message: 'boom')];
        yield 'whois' => [new WhoisException(message: 'boom')];
    }

    private function fakeWhoisReturning(?VendorTldInfo $vendorInfo): FakeWhois
    {
        $handler = static fn(): ?VendorTldInfo => $vendorInfo;

        return new FakeWhois($handler);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createVendorInfo(array $data): VendorTldInfo
    {
        $reflection = new ReflectionClass(VendorTldInfo::class);
        $vendorInfo = $reflection->newInstanceWithoutConstructor();

        if (\property_exists($vendorInfo, 'data')) {
            (new ReflectionProperty($vendorInfo, 'data'))->setValue($vendorInfo, $data);
        }

        return $vendorInfo;
    }
}
