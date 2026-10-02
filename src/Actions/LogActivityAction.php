<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Actions;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
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
        $this->stampBatch($activity);
        $this->stampLabels($activity);
        $this->truncateChanges($activity);

        parent::save($activity);

        $this->settleRoot($activity);
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
