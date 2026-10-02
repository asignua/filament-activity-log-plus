<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Asignua\FilamentActivityLogPlus\Http\Middleware\ActivityBatchMiddleware;
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

    /** Consulted for the resource when no `authorizeResource()` closure was given and the gate exists. */
    public const string GATE_RESOURCE = 'activity-log-plus.view';

    /** Consulted for the History action when no `authorizeHistory()` closure was given and the gate exists. */
    public const string GATE_HISTORY = 'activity-log-plus.history';

    protected bool|Closure|null $authorizeResource = null;

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
     * failed logins, so restrict it. Default: the `activity-log-plus.view` gate when it is
     * defined, otherwise everyone who can enter the panel.
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

        return !Gate::has(self::GATE_RESOURCE) || Gate::allows(self::GATE_RESOURCE);
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
