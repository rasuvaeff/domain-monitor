<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorBuilder;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeWhois;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DomainMonitorBuilder::class)]
final class DomainMonitorBuilderTest
{
    public function buildsHostOnlyChecksWithoutHttpOrWhois(): void
    {
        $monitor = DomainMonitorBuilder::create()->build();

        Assert::instanceOf($monitor, DomainMonitor::class);
        Assert::notNull($monitor->ssl);
        Assert::notNull($monitor->dns);
        Assert::notNull($monitor->port);
        Assert::null($monitor->httpProbe);
        Assert::null($monitor->whois);
        Assert::null($monitor->securityHeaders);
        Assert::null($monitor->robotsTxt);
        Assert::null($monitor->sitemap);
        Assert::null($monitor->content);
    }

    public function withHttpWiresEveryHttpBasedCheck(): void
    {
        $monitor = DomainMonitorBuilder::create()
            ->withHttp(client: $this->client(), requestFactory: new FakeRequestFactory())
            ->build();

        Assert::notNull($monitor->httpProbe);
        Assert::notNull($monitor->content);
        Assert::notNull($monitor->robotsTxt);
        Assert::notNull($monitor->sitemap);
        Assert::notNull($monitor->securityHeaders);
    }

    public function withWhoisWiresWhoisCheck(): void
    {
        $monitor = DomainMonitorBuilder::create()
            ->withWhois(new FakeWhois(handler: static fn(string $domain) => null))
            ->build();

        Assert::notNull($monitor->whois);
    }

    public function withoutPortDisablesPortCheck(): void
    {
        $monitor = DomainMonitorBuilder::create()->withoutPort()->build();

        Assert::null($monitor->port);
    }

    public function withoutSslAndDnsDisableThoseChecks(): void
    {
        $monitor = DomainMonitorBuilder::create()->withoutSsl()->withoutDns()->build();

        Assert::null($monitor->ssl);
        Assert::null($monitor->dns);
    }

    public function securityHeadersRequireProbeSoBuildStaysValidWithoutHttp(): void
    {
        $monitor = DomainMonitorBuilder::create()->build();

        Assert::null($monitor->securityHeaders);
    }

    public function withoutProbeAlsoDropsSecurityHeadersEvenWithHttp(): void
    {
        $monitor = DomainMonitorBuilder::create()
            ->withHttp(client: $this->client(), requestFactory: new FakeRequestFactory())
            ->withoutProbe()
            ->build();

        Assert::null($monitor->httpProbe);
        Assert::null($monitor->securityHeaders);
        Assert::notNull($monitor->content);
        Assert::notNull($monitor->robotsTxt);
        Assert::notNull($monitor->sitemap);
    }

    public function withoutHttpChecksAreIndividuallyDisablable(): void
    {
        $monitor = DomainMonitorBuilder::create()
            ->withHttp(client: $this->client(), requestFactory: new FakeRequestFactory())
            ->withoutContent()
            ->withoutRobotsTxt()
            ->withoutSitemap()
            ->withoutSecurityHeaders()
            ->build();

        Assert::notNull($monitor->httpProbe);
        Assert::null($monitor->content);
        Assert::null($monitor->robotsTxt);
        Assert::null($monitor->sitemap);
        Assert::null($monitor->securityHeaders);
    }

    private function client(): RecordingHttpClient
    {
        return new RecordingHttpClient(response: new FakeResponse(statusCode: 200));
    }
}
