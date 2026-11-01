<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Revert;

enum RevertMode: string
{
    /**
     * Write the `old` values of ONE entry back.
     */
    case Revert = 'revert';

    /**
     * Set every field changed after the entry to its state right after the entry.
     */
    case Restore = 'restore';
}
