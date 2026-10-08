<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Http\Middleware;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request to the panel = one operation in the log.
 *
 * Added to the panel by the plugin (`ActivityLogPlusPlugin::batchMiddleware()`). A save, its
 * pivot syncs and its media records then share a `batch_uuid` without touching a single
 * Filament page. Livewire update requests are covered by a `request` hook that the service
 * provider registers: persistent middleware cannot wrap them, because Livewire runs it
 * BEFORE it calls the component, not around the call.
 */
class ActivityBatchMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $batch = app(ActivityBatch::class);
        $batch->start(http: true);

        try {
            return $next($request);
        } finally {
            $batch->end();
        }
    }
}
