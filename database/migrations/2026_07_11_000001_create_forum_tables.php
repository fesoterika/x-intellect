<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Архив форума phpBB (слепок 2015 года): только чтение — темы и сообщения
// с именами авторов строками. Пользователей, регистрации и отправки
// сообщений нет по построению.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_topics', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();        // t= из phpBB
            $table->unsignedInteger('forum_old_id');            // f= из phpBB
            $table->string('forum_title');                      // название раздела форума
            $table->string('forum_group')->nullable();          // категория (Общий/Исследования/Разное)
            $table->unsignedInteger('forum_position')->default(0);
            $table->string('slug')->unique();
            $table->string('title');
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamps();

            $table->index(['forum_old_id', 'started_at']);
        });

        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum_topics')->cascadeOnDelete();
            $table->unsignedInteger('old_id')->nullable();      // p= из phpBB
            $table->string('author', 100);                      // ник строкой, без аккаунтов
            $table->timestamp('posted_at')->nullable();
            $table->text('body');
            $table->unsignedInteger('position')->default(0);    // порядок в теме
            $table->timestamps();

            $table->unique(['topic_id', 'old_id']);
            $table->index(['topic_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_topics');
    }
};
