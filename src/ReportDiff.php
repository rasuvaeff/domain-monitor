<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * A convenience wrapper over the list of {@see StatusTransition}s produced by
 * {@see ReportComparator::compare()}.
 *
 * @api
 */
final readonly class ReportDiff implements JsonSerializable
{
    /**
     * @param list<StatusTransition> $transitions
     */
    public function __construct(
        public array $transitions,
    ) {}

    public function hasChanges(): bool
    {
        return $this->transitions !== [];
    }

    /**
     * @return list<StatusTransition>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * The transition whose resulting status is the most severe. A `Disappeared`
     * transition (no resulting status) ranks below any known status. Ties keep
     * `CheckName` declaration order. `null` when there are no changes.
     */
    public function worstTransition(): ?StatusTransition
    {
        $worst = null;

        foreach ($this->transitions as $transition) {
            if ($worst === null || $this->rank(transition: $transition) > $this->rank(transition: $worst)) {
                $worst = $transition;
            }
        }

        return $worst;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'hasChanges' => $this->hasChanges(),
            'transitions' => $this->transitions,
        ];
    }

    private function rank(StatusTransition $transition): int
    {
        return $transition->to?->severity() ?? -1;
    }
}
