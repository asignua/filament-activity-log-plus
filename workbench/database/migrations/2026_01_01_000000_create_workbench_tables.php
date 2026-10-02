<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // `order` and `noindex` have DATABASE DEFAULTS, which is what makes the phantom
        // changes of `logOnlyDirty` possible.
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->json('title')->nullable();
            $table->string('slug')->nullable();
            $table->text('body')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('noindex')->default(false);
            $table->string('secret')->nullable();
            $table->string('path')->nullable();
            $table->timestamps();
        });

        Schema::create('translatable_articles', function (Blueprint $table): void {
            $table->id();
            $table->json('title')->nullable();
            $table->string('slug')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('article_tag', function (Blueprint $table): void {
            $table->foreignId('article_id');
            $table->foreignId('tag_id');
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id');
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['attachments', 'article_tag', 'tags', 'translatable_articles', 'articles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
