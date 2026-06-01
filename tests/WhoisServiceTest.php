<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Modules\Tld\TldInfo as VendorTldInfo;
use Iodev\Whois\Whois;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Rasuvaeff\DomainMonitor\WhoisService;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

#[CoversClass(WhoisService::class)]
final class WhoisServiceTest extends TestCase
{
    #[Test]
    public function mapsAllVendorFields(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'registrar' => 'Example Registrar',
            'expirationDate' => 1_769_817_600,
            'states' => ['active', 'ok'],
        ]);
        $whois = $this->createMock(Whois::class);
        $whois->method('loadDomainInfo')->willReturn($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        $this->assertNotNull($result);
        $this->assertSame('example.com', $result->domain);
        $this->assertSame('Example Registrar', $result->registrar);
        $this->assertNotNull($result->expirationDate);
        $this->assertSame(1_769_817_600, $result->expirationDate->getTimestamp());
        $this->assertSame(['active', 'ok'], $result->states);
    }

    #[Test]
    public function defaultsMissingOptionalFields(): void
    {
        $vendorInfo = $this->createVendorInfo(['domainName' => 'example.com']);
        $whois = $this->createMock(Whois::class);
        $whois->method('loadDomainInfo')->willReturn($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        $this->assertNotNull($result);
        $this->assertSame('example.com', $result->domain);
        $this->assertNull($result->registrar);
        $this->assertNull($result->expirationDate);
        $this->assertSame([], $result->states);
    }

    #[Test]
    public function ignoresNonStringStateEntries(): void
    {
        $vendorInfo = $this->createVendorInfo([
            'domainName' => 'example.com',
            'states' => ['ok', '', 42, 'clientHold'],
        ]);
        $whois = $this->createMock(Whois::class);
        $whois->method('loadDomainInfo')->willReturn($vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'example.com');

        $this->assertNotNull($result);
        $this->assertSame(['ok', 'clientHold'], $result->states);
    }

    #[Test]
    public function retriesWithBaseDomainWhenSubdomainLookupFails(): void
    {
        $vendorInfo = $this->createVendorInfo(['domainName' => 'example.com']);
        $whois = $this->createMock(Whois::class);
        $whois->expects($this->exactly(2))
            ->method('loadDomainInfo')
            ->willReturnOnConsecutiveCalls(null, $vendorInfo);

        $result = (new WhoisService(whois: $whois))->check(host: 'a.b.example.com');

        $this->assertNotNull($result);
        $this->assertSame('example.com', $result->domain);
    }

    #[Test]
    public function returnsNullWhenLookupAndFallbackBothFail(): void
    {
        $whois = $this->createMock(Whois::class);
        $whois->expects($this->exactly(2))
            ->method('loadDomainInfo')
            ->willReturn(null);

        $this->assertNull((new WhoisService(whois: $whois))->check(host: 'www.example.com'));
    }

    #[Test]
    public function returnsNullWithoutFallbackForBaseDomain(): void
    {
        $whois = $this->createMock(Whois::class);
        $whois->expects($this->once())
            ->method('loadDomainInfo')
            ->willReturn(null);

        $this->assertNull((new WhoisService(whois: $whois))->check(host: 'example.com'));
    }

    #[Test]
    #[DataProvider('caughtExceptionProvider')]
    public function returnsNullAndLogsOnWhoisException(Throwable $exception): void
    {
        $whois = $this->createMock(Whois::class);
        $whois->method('loadDomainInfo')->willThrowException($exception);
        $logger = new RecordingLogger();

        $result = (new WhoisService(whois: $whois, logger: $logger))->check(host: 'example.com');

        $this->assertNull($result);
        $this->assertCount(1, $logger->records);
        $this->assertSame('boom', $logger->records[0]['message']);
        $this->assertSame(['host' => 'example.com'], $logger->records[0]['context']);
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
