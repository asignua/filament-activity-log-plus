{{--
    The confirmation of a revert: exactly what will be written, what changed again since
    the entry (a conflict) and what can never be written.

    $plan: ?Asignua\FilamentActivityLogPlus\Revert\RevertPlan (null until an entry is chosen).
--}}
@php
    use Asignua\FilamentActivityLogPlus\Revert\RevertPlan;
    use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
    use Asignua\FilamentActivityLogPlus\Support\ActivityValue;

    $lang = 'filament-activity-log-plus::activity-log-plus.';
@endphp

<div class="activity-log-plus">
    <div class="space-y-3 text-sm">
        @if ($plan === null)
            <p class="text-gray-500 dark:text-gray-400">{{ __($lang . 'revert.pick') }}</p>
        @elseif ($plan->restoresRecord)
            <p>{{ __($lang . 'revert.record_restore') }}</p>
        @elseif ($plan->rows === [])
            <p class="text-gray-500 dark:text-gray-400">{{ __($lang . 'revert.nothing') }}</p>
        @else
            <p class="text-gray-500 dark:text-gray-400">
                {{ __($lang . 'revert.intro_' . $plan->mode->value, ['id' => $plan->entry->getKey()]) }}
            </p>

            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-start">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'ui.field') }}</th>
                            <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'revert.current') }}</th>
                            <th class="px-3 py-1.5 text-start font-medium">{{ __($lang . 'revert.will_be') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan->rows as $row)
                            <tr class="border-t border-gray-100 align-top dark:border-white/5">
                                <td class="px-3 py-1.5 font-medium whitespace-nowrap">
                                    {{ ActivityPresenter::fieldLabel($row->field) }}
                                    @if ($row->conflict)
                                        <x-filament::badge color="warning" class="ms-1 inline-flex">{{ __($lang . 'revert.conflict') }}</x-filament::badge>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 break-words text-gray-500 dark:text-gray-400">
                                    {{ ActivityValue::render($row->current) }}
                                    @if ($row->conflict)
                                        <div class="text-xs text-warning-600 dark:text-warning-400">
                                            {{ __($lang . 'revert.expected', ['value' => ActivityValue::render($row->expected, 80)]) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 break-words">{{ ActivityValue::render($row->target) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($plan->hasConflicts())
                <p class="text-warning-600 dark:text-warning-400">{{ __($lang . 'revert.conflicts_note') }}</p>
            @endif
        @endif

        @if ($plan !== null && $plan->skipped !== [])
            <div class="text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium">{{ __($lang . 'revert.skipped') }}:</span>
                @foreach ($plan->skipped as $skip)
                    {{ ActivityPresenter::fieldLabel($skip['field']) }} ({{ __($lang . 'revert.skip_' . $skip['reason']) }})@if (! $loop->last), @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
