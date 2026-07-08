<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Показывать ли страницу в списках (разделы, «Последние материалы»,
            // поиск). false — доступна только по прямой ссылке
            // (юридические страницы: политики конфиденциальности и cookies)
            $table->boolean('is_listed')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('is_listed');
        });
    }
};
