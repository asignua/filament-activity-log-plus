<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Workbench\App\Models\HostActivity;
use Workbench\App\Models\HostLogAction;

/**
 * A host (or a bridge package) that bound its own model and action keeps them: the plugin
 * replaces spatie's defaults only.
 */
class HostOverrideTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('activitylog.activity_model', HostActivity::class);
        $app['config']->set('activitylog.actions.log_activity', HostLogAction::class);
    }

    public function test_the_hosts_own_model_and_action_are_left_alone(): void
    {
        $this->assertSame(HostActivity::class, config('activitylog.activity_model'));
        $this->assertSame(HostLogAction::class, config('activitylog.actions.log_activity'));
    }

    public function test_the_log_is_still_written_through_the_host_model(): void
    {
        $this->article();

        $this->assertInstanceOf(HostActivity::class, HostActivity::query()->firstOrFail());
        $this->assertSame('created', HostActivity::query()->firstOrFail()->event);
    }
}
