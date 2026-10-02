<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Closure;

/**
 * How one event name is presented: its label, badge colour, the short summary of a table
 * row and, optionally, a Blade view that replaces the default body of its card.
 *
 * @see ActivityEvents::register()
 */
final readonly class EventDefinition
{
    /**
     * @param Closure():string|string $label   a translation key, a ready string or a closure
     * @param string                  $color   a Filament colour: success, danger, info, warning, gray, primary
     * @param Closure|null            $summary fn (Models\Activity): string
     * @param string|null             $view    a view receiving `$entry` (the activity) and `$properties` (array)
     */
    public function __construct(
        public string $name,
        public string|Closure $label,
        public string $color = 'gray',
        public ?Closure $summary = null,
        public ?string $view = null,
    ) {}

    public function resolveLabel(): string
    {
        return is_string($this->label) ? __($this->label) : (string) ($this->label)();
    }
}
