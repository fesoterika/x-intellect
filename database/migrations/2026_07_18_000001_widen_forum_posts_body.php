<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // В MySQL text — до 64 КБ; длинные посты форума (40+ тыс. символов
        // кириллицы ≈ 80 КБ в utf8mb4) не влезали. На SQLite тип единый TEXT,
        // поэтому локально проблема не проявлялась до переезда на MySQL.
        Schema::table('forum_posts', function (Blueprint $table) {
            $table->mediumText('body')->change();
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', function (Blueprint $table) {
            $table->text('body')->change();
        });
    }
};
