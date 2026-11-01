<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Resources\Articles\ArticleResource;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // The demo (screenshots) only: the feature is off and the gate is undefined everywhere else.
        if (config('activity-log-plus.revert.enabled')) {
            Gate::define('revertActivity', fn (): bool => true);
            Gate::define('activity-log-plus.view', fn (): bool => true);
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([ArticleResource::class])
            ->plugin(ActivityLogPlusPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
