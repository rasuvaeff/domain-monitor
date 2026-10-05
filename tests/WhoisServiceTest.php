<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Modules\Tld\TldInfo as VendorTldInfo;
use Iodev\Whois\Whois;
use Psr\Log\LoggerInterface;
use Rasuvaeff\DomainMonitor\WhoisService;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use ReflectionClass;
use ReflectionProperty;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Throwable;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

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
        $whois = $this->whoisReturning($vendorInfo);

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
        $whois = $this->whoisReturning($vendorInfo);

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
        $whois = $this->whoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::same($result->states, ['ok', 'clientHold']);
    }

    public function returnsNullWhenLookupAndFallbackBothFail(): void
    {
        $whois = $this->whoisReturning(null);

        $result = (new WhoisService(whois: $whois))->check(host: 'www.example.com');

        Assert::null($result);
        verify(fn() => $whois->loadDomainInfo(Arg::any()), times: 2);
    }

    public function retriesWithBaseDomainWhenSubdomainLookupFails(): void
    {
        $vendorInfo = $this->createVendorInfo(['domainName' => 'example.com']);
        $whois = Understudy::for(Whois::class);
        when(fn() => $whois->loadDomainInfo(Arg::any()))
            ->answers(
                static fn(Invocation $call): ?VendorTldInfo => $call->arg('domain') === 'example.com' ? $vendorInfo : null,
            );

        $result = (new WhoisService(whois: $whois))->check(host: 'a.b.example.com');

        Assert::notNull($result);
        Assert::same($result->domain, 'example.com');
        verify(fn() => $whois->loadDomainInfo(Arg::any()), times: 2);
    }

    public function usesStatesKeyWhenPresent(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'states' => ['active'],
            'status' => ['wrong'],
        ]);
        $whois = $this->whoisReturning($vendorInfo);

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
        $whois = $this->whoisReturning($vendorInfo);

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
        $whois = $this->whoisReturning($vendorInfo);

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
        $whois = $this->whoisReturning($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::notNull($result);
        Assert::null($result->expirationDate);
    }

    public function returnsNullForShortHostWithoutFallback(): void
    {
        $whois = $this->whoisReturning(null);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        Assert::null($result);
        verify(fn() => $whois->loadDomainInfo(Arg::any()), times: 1);
    }

    #[DataProvider('caughtExceptionProvider')]
    public function returnsNullAndLogsOnWhoisException(Throwable $exception): void
    {
        $whois = Understudy::for(Whois::class);
        when(fn() => $whois->loadDomainInfo(Arg::any()))->throws($exception);
        $logger = Understudy::for(LoggerInterface::class);

        $result = (new WhoisService(whois: $whois, logger: $logger))->check(host: 'example.com');

        Assert::null($result);
        verify(fn() => $logger->error('boom', ['host' => 'example.com']));
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

    private function whoisReturning(?VendorTldInfo $vendorInfo): Whois
    {
        $whois = Understudy::for(Whois::class);

        when(fn() => $whois->loadDomainInfo(Arg::any()))->returns($vendorInfo);

        return $whois;
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
