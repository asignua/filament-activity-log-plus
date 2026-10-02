<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
use Closure;

/**
 * The registry of event names the log knows how to present.
 *
 * Built in: created, updated, deleted, restored, pivot_synced, media_added, media_removed,
 * login, logout, login_failed, lockout, role_attached, role_detached. An event that is not
 * registered still works: it is shown as its raw name, in grey.
 *
 *     ActivityEvents::register(
 *         'approved',
 *         'Approved',                 // a translation key or a ready string
 *         color: 'success',
 *         summary: fn (Activity $a): string => (string) $a->getProperty('note'),
 *         view: 'my-app::activity.approved',   // optional: replaces the card body
 *     );
 *
 * Registering an existing name (also a built-in one) replaces it.
 */
final class ActivityEvents
{
    private const string LANG = 'filament-activity-log-plus::activity-log-plus.events.';

    /** @var array<string, EventDefinition> */
    private static array $registered = [];

    /** @var array<string, EventDefinition>|null */
    private static ?array $builtIn = null;

    /**
     * @param Closure():string|string $label
     */
    public static function register(
        string $name,
        string|Closure $label,
        string $color = 'gray',
        ?Closure $summary = null,
        ?string $view = null,
    ): void {
        self::$registered[$name] = new EventDefinition($name, $label, $color, $summary, $view);
    }

    public static function get(?string $event): ?EventDefinition
    {
        if ($event === null || $event === '') {
            return null;
        }

        return self::$registered[$event] ?? self::builtIn()[$event] ?? null;
    }

    public static function label(?string $event): string
    {
        if ($event === null || $event === '') {
            return '—';
        }

        return self::get($event)?->resolveLabel() ?? $event;
    }

    public static function color(?string $event): string
    {
        $definition = self::get($event);

        return $definition === null ? 'gray' : $definition->color;
    }

    /**
     * Every known name: the built-in ones and the registered ones.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$registered + self::builtIn());
    }

    /**
     * Forget everything registered at run time (tests, Octane workers that re-boot).
     */
    public static function flush(): void
    {
        self::$registered = [];
    }

    /**
     * @return array<string, EventDefinition>
     */
    private static function builtIn(): array
    {
        if (self::$builtIn !== null) {
            return self::$builtIn;
        }

        $simple = static fn (string $name, string $color, ?Closure $summary = null): EventDefinition => new EventDefinition($name, self::LANG.$name, $color, $summary);

        $file = static fn (Activity $a): string => (string) ($a->getProperty('file_name') ?? $a->getProperty('name') ?? '');
        $email = static fn (Activity $a): string => (string) ($a->getProperty('email') ?? '');
        $roles = static fn (Activity $a): string => implode(', ', array_map('strval', (array) $a->getProperty('roles', [])));

        $definitions = [
            $simple('created', 'success'),
            $simple('updated', 'gray'),
            $simple('deleted', 'danger'),
            $simple('restored', 'gray'),
            $simple('pivot_synced', 'info', ActivityPresenter::pivotSummary(...)),
            $simple('media_added', 'success', $file),
            $simple('media_removed', 'danger', $file),
            $simple('login', 'success'),
            $simple('logout', 'gray'),
            $simple('login_failed', 'danger', $email),
            $simple('lockout', 'danger', $email),
            $simple('role_attached', 'success', $roles),
            $simple('role_detached', 'danger', $roles),
        ];

        $map = [];

        foreach ($definitions as $definition) {
            $map[$definition->name] = $definition;
        }

        return self::$builtIn = $map;
    }
}
