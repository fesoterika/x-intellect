<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            // Отдельно от is_visible (который целиком открывает/закрывает раздел
            // и его страницы): позволяет убрать плитку с главной, оставив сам
            // раздел и его страницы полностью доступными по прямым ссылкам.
            $table->boolean('show_on_home')->default(true)->after('is_visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('show_on_home');
        });
    }
};
