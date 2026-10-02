<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Authentication events and role changes in the log (`log_auth`).
 *
 * Login and logout answer "who did it" even when the change itself left no trace. Failed
 * attempts are the cheapest signal of a password-guessing run against the panel; the volume
 * is bounded by pruning. A role change is its own entry: spatie/laravel-permission writes it
 * to a pivot, so it never shows in the diff of the user (the same problem as every pivot).
 * The role events are silent unless `permission.events_enabled` is on, which the service
 * provider forces when `log_auth` is enabled.
 *
 * Event names: login, logout, login_failed, lockout, role_attached, role_detached.
 */
class AuthActivitySubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Lockout::class, [self::class, 'handleLockout']);
        $events->listen(RoleAttachedEvent::class, [self::class, 'handleRoleAttached']);
        $events->listen(RoleDetachedEvent::class, [self::class, 'handleRoleDetached']);
    }

    public function handleLogin(Login $event): void
    {
        $this->authActivity('login', $event->user, ['guard' => $event->guard]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->authActivity('logout', $event->user, ['guard' => $event->guard]);
    }

    public function handleFailed(Failed $event): void
    {
        // There may be no subject at all (an unknown email): what is left is the attempt
        // itself with the address and the IP, which is exactly why it is logged.
        // The user of a failed attempt is the TARGET, not the actor: whoever typed the wrong
        // password is unknown, so the account is the subject and the causer stays empty.
        // Otherwise a password-guessing run would read as the victim's own actions.
        $this->authActivity('login_failed', $event->user, [
            'guard' => $event->guard,
            'email' => $this->attemptedLogin($event->credentials),
        ], actor: false);
    }

    public function handleLockout(Lockout $event): void
    {
        $this->authActivity('lockout', null, [
            'email' => $this->attemptedLogin((array) $event->request->only(['email', 'login'])),
        ]);
    }

    public function handleRoleAttached(RoleAttachedEvent $event): void
    {
        $this->roleActivity('role_attached', $event->model, $event->rolesOrIds);
    }

    public function handleRoleDetached(RoleDetachedEvent $event): void
    {
        $this->roleActivity('role_detached', $event->model, $event->rolesOrIds);
    }

    /**
     * @param array<string, mixed> $properties
     * @param bool                 $actor      Is the user also the one who acted (false for a failed attempt)?
     */
    private function authActivity(string $event, ?Authenticatable $user, array $properties, bool $actor = true): void
    {
        if (!$this->enabled()) {
            return;
        }

        $logger = activity('auth')->event($event);

        if ($user instanceof Model) {
            $logger->performedOn($user);
        }

        if ($user instanceof Model && $actor) {
            $logger->causedBy($user);
        } else {
            $logger->causedByAnonymous();
        }

        $logger->withProperties(array_filter($properties, static fn (mixed $value): bool => $value !== null))
            ->log($event);
    }

    private function roleActivity(string $event, Model $model, mixed $rolesOrIds): void
    {
        if (!$this->enabled()) {
            return;
        }

        activity('auth')
            ->performedOn($model)
            ->event($event)
            ->withProperties(['roles' => $this->roleNames($rolesOrIds)])
            ->log($event);
    }

    /**
     * spatie passes ANYTHING to the event: ids, names or role models (see the docblock of
     * RoleAttachedEvent). The log must hold the NAME: an id means nothing a year later.
     *
     * @return list<string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        $names = [];
        $ids = [];

        foreach (is_iterable($rolesOrIds) ? $rolesOrIds : [$rolesOrIds] as $role) {
            if ($role instanceof Model) {
                $name = $role->getAttribute('name');
                $names[] = is_string($name) ? $name : (string) $role->getKey();

                continue;
            }

            if (is_int($role) || (is_string($role) && ctype_digit($role))) {
                $ids[] = (int) $role;

                continue;
            }

            if (is_scalar($role)) {
                $names[] = (string) $role;
            }
        }

        if ($ids !== []) {
            /** @var class-string<Model> $roleModel */
            $roleModel = config('permission.models.role');

            foreach ($roleModel::query()->whereKey($ids)->get() as $role) {
                $name = $role->getAttribute('name');
                $names[] = is_string($name) ? $name : (string) $role->getKey();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function attemptedLogin(array $credentials): ?string
    {
        foreach (['email', 'login', 'username'] as $field) {
            $value = $credentials[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        return (bool) config('activity-log-plus.enabled', true)
            && (bool) config('activity-log-plus.log_auth', true);
    }
}
