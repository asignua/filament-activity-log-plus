<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles\Pages;

use Asignua\FilamentActivityLogPlus\Actions\ActivityHistoryAction;
use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Articles\ArticleResource;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [ActivityHistoryAction::make()];
    }
}
