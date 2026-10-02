<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Pages;

use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLog extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;
}
