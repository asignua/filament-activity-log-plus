<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Revert;

/**
 * One field of a plan. `field` is the key of the diff (`title.uk` for a translatable
 * column); `conflict` means the current value is not the one the log says the entry left.
 */
final readonly class RevertRow
{
    public function __construct(
        public string $field,
        public mixed $current,
        public mixed $target,
        public mixed $expected,
        public bool $conflict,
        public ?string $currentKey = null,
    ) {}
}
