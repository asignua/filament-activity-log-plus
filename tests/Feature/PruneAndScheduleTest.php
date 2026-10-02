<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Asignua\FilamentActivityLogPlus\Schedule;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class PruneAndScheduleTest extends TestCase
{
    private function staleEntry(int $daysOld): Activity
    {
        $article = $this->article();
        $entry = Activity::query()->latest('id')->firstOrFail()->replicate();
        $entry->created_at = now()->subDays($daysOld);
        $entry->save();

        $this->assertNotNull($article);

        return $entry;
    }

    public function test_prune_removes_entries_past_retention_only(): void
    {
        $stale = $this->staleEntry(400);

        $this->artisan('activity-log-plus:prune', ['--days' => 365])->assertSuccessful();

        $this->assertNull(Activity::query()->find($stale->id));
        $this->assertGreaterThan(0, Activity::query()->count());
    }

    public function test_prune_uses_the_config_when_no_option_is_given(): void
    {
        config(['activity-log-plus.retention_days' => 30]);
        $stale = $this->staleEntry(60);

        $this->artisan('activity-log-plus:prune')->assertSuccessful();

        $this->assertNull(Activity::query()->find($stale->id));
    }

    public function test_zero_disables_pruning(): void
    {
        config(['activity-log-plus.retention_days' => 0]);
        $stale = $this->staleEntry(4000);

        $this->artisan('activity-log-plus:prune')->assertSuccessful();

        $this->assertNotNull(Activity::query()->find($stale->id));
    }

    public function test_prune_works_in_chunks(): void
    {
        $this->article();
        $template = Activity::query()->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $copy = $template->replicate();
            $copy->created_at = now()->subDays(900);
            $copy->save();
        }

        $this->assertSame(5, app(ActivityRepository::class)->prune(now()->subDays(365), 2));
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_the_schedule_is_off_by_default(): void
    {
        $schedule = new LaravelSchedule;
        Schedule::register($schedule);

        $this->assertCount(0, $schedule->events());
    }

    public function test_the_schedule_registers_the_prune_at_the_configured_time(): void
    {
        config(['activity-log-plus.schedule.enabled' => true, 'activity-log-plus.schedule.time' => '02:15']);

        $schedule = new LaravelSchedule;
        Schedule::register($schedule);

        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertStringContainsString('activity-log-plus:prune', $events[0]->command);
        $this->assertSame('15 2 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
