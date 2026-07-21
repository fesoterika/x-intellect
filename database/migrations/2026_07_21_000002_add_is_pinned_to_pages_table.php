<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Закреплённые материалы идут первыми в листингах разделов
            // (внутри — выбранная посетителем сортировка) и в списке админки.
            // Индекс: колонка стоит первой в ORDER BY каждого листинга.
            $table->boolean('is_pinned')->default(false)->after('in_wiki_menu');
            $table->index(['is_pinned', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['is_pinned', 'section_id']);
            $table->dropColumn('is_pinned');
        });
    }
};
