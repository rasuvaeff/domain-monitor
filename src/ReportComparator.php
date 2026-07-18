<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * Compares two {@see DomainHealthReport} snapshots of the same host and reports
 * the per-check status changes between them. Stateless: the application owns the
 * previous snapshot (from storage) and decides how to react to the transitions.
 *
 * @api
 */
final readonly class ReportComparator
{
    /**
     * @return list<StatusTransition> one entry per changed check, in `CheckName` declaration order
     */
    public function diff(DomainHealthReport $previous, DomainHealthReport $current): array
    {
        $before = $this->index(report: $previous);
        $after = $this->index(report: $current);

        $transitions = [];

        foreach (CheckName::cases() as $check) {
            $from = $before[$check->value] ?? null;
            $to = $after[$check->value] ?? null;

            if ($from === $to) {
                continue;
            }

            $transitions[] = new StatusTransition(
                check: $check,
                from: $from,
                to: $to,
                kind: $this->kind(from: $from, to: $to),
            );
        }

        return $transitions;
    }

    public function compare(DomainHealthReport $previous, DomainHealthReport $current): ReportDiff
    {
        return new ReportDiff(transitions: $this->diff(previous: $previous, current: $current));
    }

    /**
     * @return array<string, CheckStatus>
     */
    private function index(DomainHealthReport $report): array
    {
        $map = [];

        foreach ($report->getChecks() as $result) {
            $map[$result->check->value] = $result->status;
        }

        return $map;
    }

    private function kind(?CheckStatus $from, ?CheckStatus $to): TransitionKind
    {
        if (!$from instanceof CheckStatus) {
            return TransitionKind::Appeared;
        }

        if (!$to instanceof CheckStatus) {
            return TransitionKind::Disappeared;
        }

        if ($from === CheckStatus::UNKNOWN || $to === CheckStatus::UNKNOWN) {
            return TransitionKind::Changed;
        }

        return $to->severity() > $from->severity()
            ? TransitionKind::Degraded
            : TransitionKind::Recovered;
    }
}
