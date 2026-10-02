<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Articles\Pages\EditArticle;
use Workbench\App\Filament\Resources\Articles\Pages\ListArticles;
use Workbench\App\Models\Article;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('slug')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
