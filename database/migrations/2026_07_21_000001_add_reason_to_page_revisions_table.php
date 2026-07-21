<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_revisions', function (Blueprint $table) {
            // Причина изменения — текст редактора. Отдельно от note: тот
            // генерируется автоматически, и по нему импортёры отличают ручные
            // правки (see ImportOfflineWiki), так что правка руками его ломает.
            $table->string('reason', 500)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('page_revisions', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
