<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use DateTimeInterface;

/**
 * An article whose model serialises dates the legacy way (no timezone), as many apps do
 * through `serializeDate()`: the log keeps `Y-m-d H:i:s`, not ISO-8601.
 */
class LegacyDateArticle extends Article
{
    protected $table = 'articles';

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
