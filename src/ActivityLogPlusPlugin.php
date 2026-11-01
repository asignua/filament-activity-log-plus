<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Asignua\FilamentActivityLogPlus\Http\Middleware\ActivityBatchMiddleware;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\ActivityLogResource;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The panel side of the package: the read-only "Activity log" resource and the batch
 * middleware. The History action ({@see Actions\ActivityHistoryAction}) works without it.
 *
 *     ->plugin(ActivityLogPlusPlugin::make()
 *         ->authorizeResource(fn (): bool => auth()->user()?->isAdmin())
 *         ->navigationGroup('System'))
 *
 * Recording (the trait, the batch, pivots, media, auth) does NOT depend on the plugin.
 */
class ActivityLogPlusPlugin implements Plugin
{
    public const string ID = 'filament-activity-log-plus';

    /**
     * Consulted for the resource when no `authorizeResource()` closure was given. Without the
     * closure AND without this gate the resource is closed to everyone (deny by default).
     */
    public const string GATE_RESOURCE = 'activity-log-plus.view';

    /** Consulted for the History action when no `authorizeHistory()` closure was given and the gate exists. */
    public const string GATE_HISTORY = 'activity-log-plus.history';

    /**
     * The ability that lets a user Revert / Restore. Defined by the application; without it
     * (and without an `authorizeRevert()` closure) nobody may revert. The log entry is passed.
     */
    public const string GATE_REVERT = 'revertActivity';

    protected bool|Closure|null $authorizeResource = null;

    protected bool|Closure|null $revertable = null;

    protected bool|Closure|null $authorizeRevert = null;

    /** @var list<string> */
    protected array $revertExcept = [];

    protected ?Closure $revertUsing = null;

    protected bool|Closure|null $revertWithoutPolicy = null;

    protected bool|Closure|null $authorizeHistory = null;

    protected bool|Closure $resource = true;

    protected bool|Closure $batchMiddleware = true;

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|BackedEnum|Closure|null $navigationIcon = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * Who may open the log resource. It shows other people's actions and the addresses of
     * failed logins, so it is closed by default: without this closure the
     * `activity-log-plus.view` gate decides, and without the gate nobody sees the resource.
     */
    public function authorizeResource(bool|Closure $callback): static
    {
        $this->authorizeResource = $callback;

        return $this;
    }

    /**
     * Who sees the History action on a record. Default: the `activity-log-plus.history`
     * gate when it is defined, otherwise everyone (whoever may edit a record should see who
     * changed it before).
     */
    public function authorizeHistory(bool|Closure $callback): static
    {
        $this->authorizeHistory = $callback;

        return $this;
    }

    /**
     * Turn Revert / Restore on (it is OFF by default; the `revert.enabled` config is the
     * fallback when this is never called). Turning it on does not grant anything by itself:
     * the user still needs the `revertActivity` gate and the `update` policy of the record.
     */
    public function revertable(bool|Closure $condition = true): static
    {
        $this->revertable = $condition;

        return $this;
    }

    /**
     * Models (class or morph alias) that can never be reverted, besides the
     * `revert.except_models` config.
     *
     * @param list<string> $models
     */
    public function revertableExcept(array $models): static
    {
        $this->revertExcept = $models;

        return $this;
    }

    /**
     * Who may revert. Default: the `revertActivity` gate (deny when it is not defined). The
     * closure receives the log entry.
     */
    public function authorizeRevert(bool|Closure $callback): static
    {
        $this->authorizeRevert = $callback;

        return $this;
    }

    /**
     * Write the reverted values yourself instead of `forceFill()->save()` (an application
     * whose writes go through a repository / DTO). The closure receives the record, the
     * column => value array to write (a translatable column as its WHOLE locale map) and the
     * source entry. It runs inside the revert's transaction; the revert is still logged.
     *
     *     ->revertUsing(fn (Model $record, array $values, Activity $entry) => app(PostRepository::class)->revert($record, $values))
     */
    public function revertUsing(?Closure $callback): static
    {
        $this->revertUsing = $callback;

        return $this;
    }

    /**
     * Let a record that has NO policy be reverted by whoever passes the `revertActivity`
     * gate. Off by default: an app that authorizes by roles rather than policies would
     * otherwise let that gate write to any model (users, settings). Config fallback:
     * `revert.require_policy` = false.
     */
    public function revertWithoutPolicy(bool|Closure $condition = true): static
    {
        $this->revertWithoutPolicy = $condition;

        return $this;
    }

    public function allowsRevertWithoutPolicy(): bool
    {
        return $this->revertWithoutPolicy !== null
            ? (bool) value($this->revertWithoutPolicy)
            : !(bool) config('activity-log-plus.revert.require_policy', true);
    }

    public function isRevertable(): bool
    {
        return $this->revertable !== null
            ? (bool) value($this->revertable)
            : (bool) config('activity-log-plus.revert.enabled', false);
    }

    /**
     * @return list<string>
     */
    public function getRevertExcept(): array
    {
        return $this->revertExcept;
    }

    public function getRevertUsing(): ?Closure
    {
        return $this->revertUsing;
    }

    public function canRevert(Activity $entry): bool
    {
        if ($this->authorizeRevert !== null) {
            return (bool) value($this->authorizeRevert, $entry);
        }

        return Gate::has(self::GATE_REVERT) && Gate::allows(self::GATE_REVERT, $entry);
    }

    /**
     * Register the "Activity log" resource. Turn it off when you only want the History action.
     */
    public function resource(bool|Closure $condition = true): static
    {
        $this->resource = $condition;

        return $this;
    }

    /**
     * Add {@see ActivityBatchMiddleware} to the panel (one request = one operation). Turn it
     * off if you open batches yourself.
     */
    public function batchMiddleware(bool|Closure $condition = true): static
    {
        $this->batchMiddleware = $condition;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function navigationIcon(string|BackedEnum|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return value($this->navigationGroup);
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return value($this->navigationIcon);
    }

    /**
     * The plugin of the current panel, or a default one when it is not registered there.
     */
    public static function resolve(): static
    {
        return filament()->hasPlugin(static::ID) ? static::get() : static::make();
    }

    public static function allowsResource(): bool
    {
        return static::resolve()->canViewResource();
    }

    public static function allowsHistory(): bool
    {
        return static::resolve()->canViewHistory();
    }

    public function canViewResource(): bool
    {
        if ($this->authorizeResource !== null) {
            return (bool) value($this->authorizeResource);
        }

        // Deny by default: the log holds every user's actions, the IPs and the addresses typed
        // into the login form, which the lowest panel role must not read just because the
        // plugin was registered without a rule.
        return Gate::has(self::GATE_RESOURCE) && Gate::allows(self::GATE_RESOURCE);
    }

    public function canViewHistory(): bool
    {
        if ($this->authorizeHistory !== null) {
            return (bool) value($this->authorizeHistory);
        }

        return !Gate::has(self::GATE_HISTORY) || Gate::allows(self::GATE_HISTORY);
    }

    public function register(Panel $panel): void
    {
        if ((bool) value($this->resource)) {
            $panel->resources([ActivityLogResource::class]);
        }

        if ((bool) value($this->batchMiddleware) && (bool) config('activity-log-plus.batches', true)) {
            $panel->middleware([ActivityBatchMiddleware::class]);
        }

        // After the panel's theme, not before it as auto-loaded plugin assets are: a custom
        // theme compiles the same utilities, and with equal specificity the later file wins.
        $panel->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => '<link rel="stylesheet" href="'
            .e(FilamentAsset::getStyleHref(ActivityLogPlusServiceProvider::STYLESHEET, ActivityLogPlusServiceProvider::PACKAGE)).'" />');
    }

    public function boot(Panel $panel): void {}
}
