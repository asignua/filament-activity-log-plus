<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AuthEventsTest extends TestCase
{
    private function last(): Activity
    {
        return Activity::query()->latest('id')->firstOrFail();
    }

    public function test_login_and_logout_are_logged_against_the_user(): void
    {
        $user = $this->admin();
        Activity::query()->delete();

        event(new Login('web', $user, false));
        $login = $this->last();

        $this->assertSame('login', $login->event);
        $this->assertSame('auth', $login->log_name);
        $this->assertSame($user->getKey(), $login->subject_id);
        $this->assertSame($user->getKey(), $login->causer_id);
        $this->assertSame(['guard' => 'web'], $login->properties?->toArray());

        event(new Logout('web', $user));
        $this->assertSame('logout', $this->last()->event);
    }

    public function test_a_failed_login_keeps_the_attempted_address_even_without_a_user(): void
    {
        event(new Failed('web', null, ['email' => 'nobody@example.com', 'password' => 'secret']));

        $activity = $this->last();
        $raw = (string) $activity->getRawOriginal('properties');

        $this->assertSame('login_failed', $activity->event);
        $this->assertNull($activity->subject_id);
        $this->assertSame('nobody@example.com', $activity->properties?->toArray()['email']);
        $this->assertStringNotContainsString('secret', $raw);
    }

    public function test_a_failed_login_for_an_existing_account_is_not_caused_by_the_victim(): void
    {
        $victim = $this->admin();
        Activity::query()->delete();

        event(new Failed('web', $victim, ['email' => $victim->email, 'password' => 'guess']));

        $activity = $this->last();

        $this->assertSame('login_failed', $activity->event);
        $this->assertSame($victim->getKey(), $activity->subject_id);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_label);
    }

    public function test_a_lockout_is_logged_with_the_address(): void
    {
        event(new Lockout(Request::create('/login', 'POST', ['email' => 'brute@example.com'])));

        $this->assertSame('lockout', $this->last()->event);
        $this->assertSame('brute@example.com', $this->last()->properties?->toArray()['email']);
    }

    public function test_role_changes_are_logged_by_name_against_the_user(): void
    {
        $user = $this->admin();
        $role = Role::create(['name' => 'editor']);
        Activity::query()->delete();

        $user->assignRole($role);

        $attached = Activity::query()->where('event', 'role_attached')->firstOrFail();
        $this->assertSame($user->getKey(), $attached->subject_id);
        $this->assertSame(['editor'], $attached->properties?->toArray()['roles']);

        $user->removeRole($role);

        $detached = Activity::query()->where('event', 'role_detached')->firstOrFail();
        $this->assertSame(['editor'], $detached->properties?->toArray()['roles']);
    }

    public function test_the_spatie_permission_v6_event_names_are_logged_too(): void
    {
        $user = $this->admin();
        Activity::query()->delete();

        event('Spatie\\Permission\\Events\\RoleAttached', [(object) ['model' => $user, 'rolesOrIds' => ['editor']]]);
        event('Spatie\\Permission\\Events\\RoleDetached', [(object) ['model' => $user, 'rolesOrIds' => ['editor']]]);

        $this->assertSame(['role_attached', 'role_detached'], Activity::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_a_host_update_of_the_user_during_login_does_not_take_the_root_from_the_login(): void
    {
        $user = $this->admin();
        Activity::query()->delete();

        app(ActivityBatch::class)->run(function () use ($user): void {
            event(new Login('web', $user, false));
            $user->update(['name' => 'Logged in '.uniqid()]);
        });

        $root = Activity::query()->where('batch_root', true)->get();

        $this->assertCount(1, $root);
        $this->assertSame('login', $root->first()?->event);
    }

    public function test_the_permission_package_events_are_forced_on(): void
    {
        $this->assertTrue((bool) config('permission.events_enabled'));
    }

    public function test_auth_logging_can_be_switched_off(): void
    {
        config(['activity-log-plus.log_auth' => false]);
        $user = $this->admin();
        Activity::query()->delete();

        event(new Login('web', $user, false));
        event(new Failed('web', null, ['email' => 'x@example.com']));

        $this->assertSame(0, Activity::query()->count());
    }
}
