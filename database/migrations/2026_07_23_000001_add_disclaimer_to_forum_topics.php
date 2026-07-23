<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Дисклеймер темы форума: свободный текст, выводится внизу темы под
// пагинацией. Заменяет захардкоженный список «медицинских» тем
// (бывший ForumController::MEDICAL_TOPIC_SLUGS) — теперь текст приписки
// редактируется в админке per-тема.
return new class extends Migration
{
    /**
     * Темы, где участники обсуждали здоровье, болезни и «целительство»
     * (список из правового обзора форума 18.07.2026) — им переносится
     * прежний общий текст приписки.
     */
    private const MEDICAL_TOPIC_SLUGS = [
        'vic-infekciia',
        'voprosy-po-proektu-izosfera-i-parallelnymi-miry',
        'socialnye-iavleniia-zemli-civilizaciia-zemli',
        'celitelstvo-praktika',
        'novogodniaia-konferenciia-2016',
        'volnovaia-genetika',
        'etalony-osoboe-mnenie',
        'obsuzdenie-temy-celovek-zemlia-kosmos',
    ];

    private const MEDICAL_DISCLAIMER = 'Сообщения отражают личные мнения участников тех лет, не являются позицией администрации сайта '
        .'и не могут служить медицинскими или иными рекомендациями. Сведения о здоровье и заболеваниях '
        .'в этой теме могут быть недостоверны — по вопросам здоровья обращайтесь к врачу. '
        .'Материалы публикуются как исторический архив дискуссии и не содержат призывов '
        .'или пропаганды чего-либо.';

    public function up(): void
    {
        Schema::table('forum_topics', function (Blueprint $table) {
            $table->text('disclaimer')->nullable()->after('posts_count');
        });

        DB::table('forum_topics')
            ->whereIn('slug', self::MEDICAL_TOPIC_SLUGS)
            ->update(['disclaimer' => self::MEDICAL_DISCLAIMER]);
    }

    public function down(): void
    {
        Schema::table('forum_topics', function (Blueprint $table) {
            $table->dropColumn('disclaimer');
        });
    }
};
