<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use WeakMap;

/**
 * Records file uploads and removals in the log.
 *
 * Why it exists: Filament writes media DIRECTLY (a SpatieMediaLibraryFileUpload in a form),
 * past your DTOs and repositories, so the diff of the owner never shows them: "deleted the
 * picture" would leave no trace at all. The entry is written against the OWNER of the file,
 * so it lands in the history of the record itself and, thanks to the batch, in the same
 * operation as the form save.
 *
 * spatie/laravel-medialibrary is wired automatically when it is installed (events
 * `media_added` / `media_removed`, properties `collection`, `file_name`, `name`, `mime_type`,
 * `size`). Any other library, or a pivot-like "attach from the library" model, is plugged in
 * with {@see self::register()}:
 *
 *     MediaAdapters::register(
 *         MediaAttachment::class,
 *         owner: fn (MediaAttachment $a): ?Model => $a->attachable,
 *         properties: fn (MediaAttachment $a): array => ['name' => $a->file?->name],
 *         events: ['created' => 'media_attached', 'deleted' => 'media_detached'],
 *     );
 *
 * Register the labels of custom event names with {@see ActivityEvents::register()}.
 * Both `enabled` and `log_media` are read when the event fires, so they can be switched at run time.
 */
final class MediaAdapters
{
    public const array DEFAULT_EVENTS = ['created' => 'media_added', 'deleted' => 'media_removed'];

    /** @var WeakMap<object, array<string, true>>|null */
    private static ?WeakMap $registered = null;

    /**
     * @param class-string<Model>                  $class      the Eloquent model that represents a file/attachment
     * @param Closure(Model): ?Model               $owner      the record the file belongs to
     * @param Closure(Model): array<string, mixed> $properties stored as the `properties` of the entry
     * @param array<string, string>                $events     Eloquent event (`created`, `deleted`, ...) => log event name
     */
    public static function register(string $class, Closure $owner, Closure $properties, array $events = self::DEFAULT_EVENTS): void
    {
        // Model listeners live on the event dispatcher, which is a new object whenever the
        // application is rebuilt (every test, every Octane worker). The guard against registering
        // twice is therefore kept PER DISPATCHER: a plain static list would skip the second boot
        // and leave the fresh dispatcher without the listener.
        $dispatcher = $class::getEventDispatcher();

        if ($dispatcher === null) {
            return;
        }

        self::$registered ??= new WeakMap;
        $done = self::$registered[$dispatcher] ?? [];

        foreach ($events as $eloquentEvent => $event) {
            $key = $class.'@'.$eloquentEvent;

            if (isset($done[$key])) {
                continue;
            }

            $done[$key] = true;
            self::$registered[$dispatcher] = $done;

            $dispatcher->listen("eloquent.{$eloquentEvent}: ".$class, static function (Model $model) use ($owner, $properties, $event): void {
                self::record($model, $event, $owner, $properties);
            });
        }
    }

    /**
     * The built-in adapter for spatie/laravel-medialibrary; a no-op when it is not installed.
     */
    public static function registerDefaults(): void
    {
        if (!class_exists(Media::class)) {
            return;
        }

        self::register(
            Media::class,
            static fn (Model $media): ?Model => $media instanceof Media ? $media->model : null,
            static fn (Model $media): array => $media instanceof Media ? [
                'collection' => $media->collection_name,
                'file_name' => $media->file_name,
                'name' => $media->name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
            ] : [],
        );
    }

    /**
     * @param Closure(Model): ?Model               $owner
     * @param Closure(Model): array<string, mixed> $properties
     */
    private static function record(Model $model, string $event, Closure $owner, Closure $properties): void
    {
        if (!(bool) config('activity-log-plus.enabled', true) || !(bool) config('activity-log-plus.log_media', true)) {
            return;
        }

        $logger = activity()->event($event);

        $subject = $owner($model);

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        $logger->withProperties($properties($model))->log($event);
    }
}
