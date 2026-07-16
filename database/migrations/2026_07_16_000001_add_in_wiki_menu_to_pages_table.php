<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Выводить ли страницу в боковом меню вики. По умолчанию false —
            // меню наполняется только явно отмеченными страницами.
            $table->boolean('in_wiki_menu')->default(false)->after('is_listed');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('in_wiki_menu');
        });
    }
};
