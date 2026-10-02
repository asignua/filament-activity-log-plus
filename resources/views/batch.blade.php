{{--
    One operation of the activity log: every entry sharing a batch_uuid (or, in the History
    action, the latest entries of one record). This is where "related things" come together:
    the save of a record, its pivot changes and the files of the same form submit.

    $activities: iterable of Activity.
--}}
@php
    use Asignua\FilamentActivityLogPlus\ActivityEvents;
    use Asignua\FilamentActivityLogPlus\SubjectLabels;
    use Asignua\FilamentActivityLogPlus\Support\ActivityDiff;
    use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
    use Asignua\FilamentActivityLogPlus\Support\ActivityValue;

    $lang = 'filament-activity-log-plus::activity-log-plus.ui.';
@endphp

<div class="activity-log-plus space-y-4 text-sm">
    @foreach ($activities as $entry)
        @php
            $changes = $entry->attribute_changes?->toArray() ?? [];
            $attributes = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
            $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
            $truncated = is_array($changes['truncated'] ?? null) ? $changes['truncated'] : [];
            $properties = $entry->properties?->toArray() ?? [];
            $keys = ActivityDiff::changedKeys($changes);
            $definition = ActivityEvents::get($entry->event);
        @endphp

        <div class="rounded-lg border border-gray-200 dark:border-white/10">
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <x-filament::badge :color="ActivityPresenter::eventColor($entry->event)">
                    {{ ActivityPresenter::eventLabel($entry->event) }}
                </x-filament::badge>

                <span class="font-medium">{{ $entry->subject_label ?? '—' }}</span>

                <span class="text-gray-500 dark:text-gray-400">
                    {{ SubjectLabels::typeLabel($entry->subject_type) }}
                </span>

                <span class="ms-auto text-xs text-gray-500 dark:text-gray-400">
                    {{ $entry->created_at?->format('d.m.Y H:i:s') }}
                </span>
            </div>

            @if ($keys !== [])
                <div class="overflow-x-auto">
                    <table class="w-full text-start">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'field') }}</th>
                                <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'old_value') }}</th>
                                <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'new_value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($keys as $key)
                                <tr class="border-t border-gray-100 align-top dark:border-white/5">
                                    <td class="px-3 py-1.5 font-medium whitespace-nowrap">
                                        {{ ActivityPresenter::fieldLabel($key) }}
                                        @if (in_array($key, $truncated, true))
                                            <span class="text-xs font-normal text-gray-400">({{ __($lang . 'truncated') }})</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 break-words text-gray-500 dark:text-gray-400">
                                        {{ ActivityValue::render($old[$key] ?? null) }}
                                    </td>
                                    <td class="px-3 py-1.5 break-words">
                                        {{ ActivityValue::render($attributes[$key] ?? null) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($definition?->view !== null)
                @include($definition->view, ['entry' => $entry, 'properties' => $properties])
            @elseif (ActivityPresenter::isPivotShaped($properties))
                <div class="space-y-1 px-3 py-2">
                    <div class="font-medium">{{ $properties['label'] ?? $properties['key'] ?? '' }}</div>

                    @foreach ([['attached', __($lang . 'attached'), 'success'], ['detached', __($lang . 'detached'), 'danger']] as [$side, $sideLabel, $color])
                        @if (! empty($properties[$side]))
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="text-gray-500 dark:text-gray-400">{{ $sideLabel }}:</span>
                                @foreach ($properties[$side] as $item)
                                    <x-filament::badge :color="$color">{{ $item['title'] ?? '#' . ($item['id'] ?? '?') }}</x-filament::badge>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            @elseif ($properties !== [] && $keys === [])
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 px-3 py-2">
                    @foreach ($properties as $name => $value)
                        <dt class="text-gray-500 dark:text-gray-400">
                            {{ ActivityPresenter::fieldLabel((string) $name) }}
                        </dt>
                        <dd class="break-words">{{ ActivityValue::render($value) }}</dd>
                    @endforeach
                </dl>
            @endif
        </div>
    @endforeach
</div>
