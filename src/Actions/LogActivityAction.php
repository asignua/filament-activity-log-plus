<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Actions;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Asignua\FilamentActivityLogPlus\SubjectLabels;
use Asignua\FilamentActivityLogPlus\Support\ActivityDiff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;

/**
 * The single point every log entry goes through, whether it comes from the Eloquent events
 * of a model or was written by hand (pivots, media, auth). This is where the columns of the
 * plugin are stamped.
 *
 * Swapped in through `activitylog.actions.log_activity` (spatie 5 supports that explicitly),
 * deliberately NOT through `LogActivityAction::beforeLogging()`: that is a static array of
 * callbacks which would pile up with every application boot in a test suite.
 *
 * The order in the parent's `execute()`: description, `transformChanges()`, then
 * `beforeActivityLogged()` (the hook on the subject model, where the trait expands the
 * translatable fields per locale), then `save()`. Our edits live in `save()`: they must be
 * LAST, applied over the already expanded diff.
 */
class LogActivityAction extends SpatieLogActivityAction
{
    protected function save(Model $activity): void
    {
        if ($this->isEmptyUpdate($activity)) {
            return;
        }

        $this->stampBatch($activity);
        $this->stampLabels($activity);
        $this->truncateChanges($activity);

        parent::save($activity);

        $this->settleRoot($activity);
    }

    /**
     * An `updated` entry of a {@see LogsActivityPlus} model whose diff is empty AFTER the
     * trait filtered it. spatie's `dontLogEmptyChanges` looks at the RAW diff, before
     * `beforeActivityLogged()` cuts the phantom columns and the unchanged locales, so an
     * update that only re-encoded a translatable JSON column (key order, escaped unicode)
     * would otherwise land as an "Updated" row with nothing in it, and, being a lifecycle
     * event, take the root of the operation over from a real pivot/media entry.
     *
     * Only for the trait's own model entries: a hand-written `updated` entry without a diff
     * is the caller's decision.
     */
    protected function isEmptyUpdate(Model $activity): bool
    {
        if ($activity->getAttribute('event') !== 'updated') {
            return false;
        }

        $subject = $activity->getAttribute('subject');

        if (!$subject instanceof Model || !in_array(LogsActivityPlus::class, class_uses_recursive($subject), true)) {
            return false;
        }

        $properties = $activity->getAttribute('properties');

        if ($properties instanceof Collection ? $properties->isNotEmpty() : !empty($properties)) {
            return false;
        }

        $changes = $activity->getAttribute('attribute_changes');

        if ($changes instanceof Collection) {
            $changes = $changes->toArray();
        }

        return !is_array($changes) || ActivityDiff::isEmpty($changes);
    }

    /**
     * spatie's buffer saves the entries at the end of the request, so the key of a root is not
     * known while the batch is open and a later lifecycle entry could not demote it (two roots
     * in one operation). Inside a batch the entries are therefore written straight away;
     * outside one every entry is its own root and the buffer is safe.
     */
    protected function shouldBuffer(): bool
    {
        return parent::shouldBuffer() && app(ActivityBatch::class)->uuid() === null;
    }

    protected function stampBatch(Model $activity): void
    {
        $batch = app(ActivityBatch::class);

        $activity->setAttribute('batch_uuid', $batch->uuid());

        $subjectType = $activity->getAttribute('subject_type');
        $subjectId = $activity->getAttribute('subject_id');
        $subject = is_string($subjectType) && $subjectId !== null ? $subjectType.':'.$subjectId : null;

        $activity->setAttribute('batch_root', $batch->claimRoot(
            $subject,
            in_array($activity->getAttribute('event'), ActivityBatch::LIFECYCLE_EVENTS, true),
        ));
    }

    /**
     * The entry is saved: if it took the root over from an earlier secondary entry of the
     * same subject, that entry stops being the root (one UPDATE).
     */
    protected function settleRoot(Model $activity): void
    {
        $key = $activity->getKey();

        if (!is_int($key) && !is_string($key)) {
            return;
        }

        $displaced = app(ActivityBatch::class)->confirmRoot($key);

        if ($displaced !== null) {
            app(ActivityRepository::class)->demoteRoot($displaced);
        }
    }

    protected function stampLabels(Model $activity): void
    {
        $subject = $activity->getAttribute('subject');
        $causer = $activity->getAttribute('causer');

        $activity->setAttribute('subject_label', SubjectLabels::label($subject instanceof Model ? $subject : null));
        $activity->setAttribute('causer_label', SubjectLabels::label($causer instanceof Model ? $causer : null));

        if ($activity->getAttribute('ip') === null && app()->bound('request')) {
            $activity->setAttribute('ip', request()->ip());
        }
    }

    protected function truncateChanges(Model $activity): void
    {
        $changes = $activity->getAttribute('attribute_changes');

        if ($changes instanceof Collection) {
            $changes = $changes->toArray();
        }

        if (!is_array($changes) || $changes === []) {
            return;
        }

        $activity->setAttribute('attribute_changes', ActivityDiff::truncate(
            $changes,
            (int) config('activity-log-plus.max_value_length', 5000),
        ));
    }
}
