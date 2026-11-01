<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->create(['name' => 'Emma Hart', 'email' => 'emma@example.com', 'password' => Hash::make('password')]);
        auth()->login($user);
        $batch = app(ActivityBatch::class);

        $article = new Article;
        $batch->run(function () use ($article): void {
            $article->title = ['uk' => 'Весняна розсилка', 'en' => 'Spring newsletter'];
            $article->slug = 'spring-newsletter';
            $article->body = 'Draft';
            $article->save();
        });
        $batch->run(function () use ($article): void {
            $article->title = ['uk' => 'Весняна розсилка', 'en' => 'Spring newsletter 2026'];
            $article->slug = 'spring-2026';
            $article->save();
        });
        $batch->run(function () use ($article): void {
            $article->body = 'Final text';
            $article->save();
        });
        $batch->run(function () use ($article): void {
            $article->slug = 'spring-sale';
            $article->save();
        });
    }
}
