<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            // Кэш тела с проставленными ссылками глоссария (пересобирается при сохранении)
            $table->longText('body_rendered')->nullable();
            $table->string('page_type', 20)->default('page');   // page | author
            $table->string('status', 20)->default('draft');     // draft | published
            // Три эпохи контента: «Сфера Разума» (до 2012), архив X-Intellect, новые материалы
            $table->string('source_type', 30)->default('new');  // archive_sferarazuma | archive_xintellect | new
            $table->string('source_url', 2048)->nullable();     // ссылка на архивный снапшот
            $table->json('seo')->nullable();                    // meta_title, meta_description, og_image, canonical, schema_type
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->date('archived_at')->nullable();            // дата архивной редакции
            $table->timestamps();

            $table->index(['section_id', 'status', 'position']);
        });

        // FULLTEXT-поиск доступен только на MySQL/MariaDB (на проде);
        // локальный SQLite обходится LIKE-поиском
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'])) {
            Schema::table('pages', function (Blueprint $table) {
                $table->fullText(['title', 'body']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
