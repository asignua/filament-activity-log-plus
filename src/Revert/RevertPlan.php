<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Revert;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Illuminate\Database\Eloquent\Model;

/**
 * What a revert WOULD do: the fields to write, the conflicting ones and the ones that can
 * never be written. Built without touching the record, shown in the confirmation modal.
 */
final readonly class RevertPlan
{
    public const string SKIP_TRUNCATED = 'truncated';

    public const string SKIP_EXCLUDED = 'excluded';

    public const string SKIP_MISSING = 'missing';

    /**
     * @param list<RevertRow>                            $rows
     * @param list<array{field: string, reason: string}> $skipped
     * @param bool                                       $restoresRecord The plan undoes a soft delete instead of writing fields
     */
    public function __construct(
        public Activity $entry,
        public Model $subject,
        public RevertMode $mode,
        public array $rows,
        public array $skipped = [],
        public bool $restoresRecord = false,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }

    /**
     * @return list<RevertRow>
     */
    public function conflicts(): array
    {
        return array_values(array_filter($this->rows, static fn (RevertRow $row): bool => $row->conflict));
    }

    /**
     * The rows that will be written: all of them, or only the conflict-free ones.
     *
     * @return list<RevertRow>
     */
    public function applicable(bool $overwriteConflicts): array
    {
        return $overwriteConflicts
            ? $this->rows
            : array_values(array_filter($this->rows, static fn (RevertRow $row): bool => !$row->conflict));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] && !$this->restoresRecord;
    }
}
