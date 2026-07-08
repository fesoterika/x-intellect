<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('audio'); // audio | pdf | image
            $table->string('title');
            // Путь в рамках диска (storage/app/public/...) либо полный URL внешнего S3-хранилища
            $table->string('file_path', 2048);
            $table->string('disk', 30)->default('public');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();       // байты
            $table->unsignedInteger('duration')->nullable();      // секунды, для аудио
            $table->unsignedInteger('position')->default(0);      // порядок в плейлисте
            $table->timestamps();

            $table->index(['page_id', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
