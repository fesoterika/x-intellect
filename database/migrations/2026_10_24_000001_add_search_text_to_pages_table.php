<?php

use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->longText('search_text')->nullable();
        });

        // Производное поле: исходные тексты, даты и ревизии не меняем.
        DB::table('pages')->select(['id', 'title', 'excerpt', 'body'])->orderBy('id')->chunkById(50, function ($pages) {
            foreach ($pages as $page) {
                DB::table('pages')->where('id', $page->id)->update([
                    'search_text' => SearchText::index($page->title, $page->excerpt, $page->body),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', fn (Blueprint $table) => $table->dropColumn('search_text'));
    }
};
