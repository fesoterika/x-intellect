<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
        });
        Schema::table('forum_topics', fn (Blueprint $table) => $table->text('meta_description')->nullable());
        Schema::table('glossary_terms', fn (Blueprint $table) => $table->text('meta_description')->nullable());
    }

    public function down(): void
    {
        Schema::table('sections', fn (Blueprint $table) => $table->dropColumn(['meta_title', 'meta_description']));
        Schema::table('forum_topics', fn (Blueprint $table) => $table->dropColumn('meta_description'));
        Schema::table('glossary_terms', fn (Blueprint $table) => $table->dropColumn('meta_description'));
    }
};
