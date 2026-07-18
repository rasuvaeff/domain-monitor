<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\CheckError;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\ReportComparator;
use Rasuvaeff\DomainMonitor\ReportDiff;
use Rasuvaeff\DomainMonitor\StatusTransition;
use Rasuvaeff\DomainMonitor\TldInfo;
use Rasuvaeff\DomainMonitor\TransitionKind;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ReportComparator::class)]
#[Covers(StatusTransition::class)]
#[Covers(ReportDiff::class)]
#[Covers(TransitionKind::class)]
final class ReportComparatorTest
{
    private ReportComparator $comparator;

    public function setUp(): void
    {
        $this->comparator = new ReportComparator();
    }

    public function emitsNoTransitionsForIdenticalReports(): void
    {
        $report = $this->reportWithProbe(status: 200);

        Assert::same($this->comparator->diff(previous: $report, current: $report), []);
    }

    public function detectsAppearedCheck(): void
    {
        $previous = new DomainHealthReport(host: 'example.com', whois: $this->whoisOk());
        $current = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 200, totalTime: 0.1), whois: $this->whoisOk());

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same(\count($transitions), 1);
        Assert::same($transitions[0]->check, CheckName::Probe);
        Assert::null($transitions[0]->from);
        Assert::same($transitions[0]->to, CheckStatus::OK);
        Assert::same($transitions[0]->kind, TransitionKind::Appeared);
    }

    public function detectsDisappearedCheck(): void
    {
        $previous = $this->reportWithProbe(status: 200);
        $current = new DomainHealthReport(host: 'example.com');

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same(\count($transitions), 1);
        Assert::same($transitions[0]->check, CheckName::Probe);
        Assert::same($transitions[0]->from, CheckStatus::OK);
        Assert::null($transitions[0]->to);
        Assert::same($transitions[0]->kind, TransitionKind::Disappeared);
    }

    public function detectsDegradedStatus(): void
    {
        $transition = $this->singleTransition(previousStatus: 200, currentStatus: 500);

        Assert::same($transition->from, CheckStatus::OK);
        Assert::same($transition->to, CheckStatus::CRITICAL);
        Assert::same($transition->kind, TransitionKind::Degraded);
    }

    public function detectsRecoveredStatus(): void
    {
        $transition = $this->singleTransition(previousStatus: 500, currentStatus: 200);

        Assert::same($transition->from, CheckStatus::CRITICAL);
        Assert::same($transition->to, CheckStatus::OK);
        Assert::same($transition->kind, TransitionKind::Recovered);
    }

    public function treatsTransitionToUnknownAsChanged(): void
    {
        $previous = new DomainHealthReport(host: 'example.com', whois: $this->whoisOk());
        $current = new DomainHealthReport(host: 'example.com', whois: $this->whoisUnknown());

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same(\count($transitions), 1);
        Assert::same($transitions[0]->from, CheckStatus::OK);
        Assert::same($transitions[0]->to, CheckStatus::UNKNOWN);
        Assert::same($transitions[0]->kind, TransitionKind::Changed);
    }

    public function treatsTransitionFromUnknownAsChanged(): void
    {
        $previous = new DomainHealthReport(host: 'example.com', whois: $this->whoisUnknown());
        $current = new DomainHealthReport(host: 'example.com', whois: $this->whoisOk());

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same($transitions[0]->kind, TransitionKind::Changed);
    }

    public function treatsErroredCheckAsUnknown(): void
    {
        $previous = $this->reportWithProbe(status: 200);
        $current = new DomainHealthReport(host: 'example.com', errors: [new CheckError(check: CheckName::Probe, message: 'boom')]);

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same($transitions[0]->from, CheckStatus::OK);
        Assert::same($transitions[0]->to, CheckStatus::UNKNOWN);
        Assert::same($transitions[0]->kind, TransitionKind::Changed);
    }

    public function ordersTransitionsByCheckNameDeclaration(): void
    {
        $previous = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 200, totalTime: 0.1), whois: $this->whoisOk());
        $current = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 500, totalTime: 0.1), whois: $this->whoisUnknown());

        $transitions = $this->comparator->diff(previous: $previous, current: $current);

        Assert::same(\array_map(static fn(StatusTransition $t): CheckName => $t->check, $transitions), [CheckName::Probe, CheckName::Whois]);
    }

    public function serializesTransitionToArray(): void
    {
        $transition = new StatusTransition(check: CheckName::Ssl, from: CheckStatus::OK, to: CheckStatus::CRITICAL, kind: TransitionKind::Degraded);

        Assert::same($transition->jsonSerialize(), ['check' => 'ssl', 'from' => 'ok', 'to' => 'critical', 'kind' => 'degraded']);
    }

    public function serializesAppearedTransitionWithNullFrom(): void
    {
        $transition = new StatusTransition(check: CheckName::Ssl, from: null, to: CheckStatus::OK, kind: TransitionKind::Appeared);

        Assert::same($transition->jsonSerialize(), ['check' => 'ssl', 'from' => null, 'to' => 'ok', 'kind' => 'appeared']);
    }

    public function compareWrapsTransitionsInReportDiff(): void
    {
        $diff = $this->comparator->compare(previous: $this->reportWithProbe(status: 200), current: $this->reportWithProbe(status: 500));

        Assert::true($diff->hasChanges());
        Assert::same(\count($diff->getTransitions()), 1);
        Assert::same($diff->getTransitions()[0]->kind, TransitionKind::Degraded);
    }

    public function reportDiffWithoutChangesReportsNoWorstTransition(): void
    {
        $diff = $this->comparator->compare(previous: $this->reportWithProbe(status: 200), current: $this->reportWithProbe(status: 200));

        Assert::false($diff->hasChanges());
        Assert::null($diff->worstTransition());
    }

    public function reportDiffPicksMostSevereTransition(): void
    {
        $previous = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 200, totalTime: 0.1), whois: $this->whoisOk());
        // probe 200 -> 404 = OK->WARNING (degraded); whois OK -> expired = OK->CRITICAL (degraded, more severe)
        $current = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 404, totalTime: 0.1), whois: $this->whoisExpired());

        $worst = $this->comparator->compare(previous: $previous, current: $current)->worstTransition();

        Assert::notNull($worst);
        Assert::same($worst->check, CheckName::Whois);
        Assert::same($worst->to, CheckStatus::CRITICAL);
    }

    public function reportDiffSerializesToArray(): void
    {
        $diff = $this->comparator->compare(previous: $this->reportWithProbe(status: 200), current: $this->reportWithProbe(status: 500));

        Assert::same(json_decode(json_encode($diff, JSON_THROW_ON_ERROR), associative: true), [
            'hasChanges' => true,
            'transitions' => [
                ['check' => 'probe', 'from' => 'ok', 'to' => 'critical', 'kind' => 'degraded'],
            ],
        ]);
    }

    #[Property(runs: 200)]
    public function diffOfSameReportIsAlwaysEmpty(int $status): void
    {
        $report = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $status, totalTime: 0.1));

        Assert::same((new ReportComparator())->diff(previous: $report, current: $report), []);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function diffOfSameReportIsAlwaysEmptyGenerators(): array
    {
        return ['status' => Gen::intBetween(200, 599)];
    }

    #[Property(runs: 200)]
    public function degradeAndRecoverAreSymmetric(int $fromStatus, int $toStatus): void
    {
        $comparator = new ReportComparator();
        $from = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $fromStatus, totalTime: 0.1));
        $to = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $toStatus, totalTime: 0.1));

        $forward = $comparator->diff(previous: $from, current: $to);
        $backward = $comparator->diff(previous: $to, current: $from);

        $fromCheck = $from->getCheck(name: CheckName::Probe);
        $toCheck = $to->getCheck(name: CheckName::Probe);
        Assert::notNull($fromCheck);
        Assert::notNull($toCheck);

        if ($fromCheck->status === $toCheck->status) {
            Assert::same($forward, []);
            Assert::same($backward, []);

            return;
        }

        $degradedForward = $toCheck->status->severity() > $fromCheck->status->severity();

        Assert::same($forward[0]->kind, $degradedForward ? TransitionKind::Degraded : TransitionKind::Recovered);
        Assert::same($backward[0]->kind, $degradedForward ? TransitionKind::Recovered : TransitionKind::Degraded);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function degradeAndRecoverAreSymmetricGenerators(): array
    {
        return [
            'fromStatus' => Gen::intBetween(200, 599),
            'toStatus' => Gen::intBetween(200, 599),
        ];
    }

    private function singleTransition(int $previousStatus, int $currentStatus): StatusTransition
    {
        $transitions = $this->comparator->diff(
            previous: $this->reportWithProbe(status: $previousStatus),
            current: $this->reportWithProbe(status: $currentStatus),
        );

        return $transitions[0];
    }

    private function reportWithProbe(int $status): DomainHealthReport
    {
        return new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $status, totalTime: 0.1));
    }

    private function whoisOk(): TldInfo
    {
        return new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '+365 days'));
    }

    private function whoisExpired(): TldInfo
    {
        return new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '-1 day'));
    }

    private function whoisUnknown(): TldInfo
    {
        return new TldInfo(domain: 'example.com');
    }
}
