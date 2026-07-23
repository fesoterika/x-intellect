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
     * (список из правового обзора форума 18.07.2026) — общая приписка.
     */
    private const MEDICAL_TOPIC_SLUGS = [
        'celitelstvo-praktika',
        'novogodniaia-konferenciia-2016',
        'volnovaia-genetika',
        'etalony-osoboe-mnenie',
        'obsuzdenie-temy-celovek-zemlia-kosmos',
    ];

    /**
     * Темы с сообщениями, отрицающими существование ВИЧ, — усиленная
     * приписка с прямым опровержением (рекомендация обзора: суды признают
     * ВИЧ-отрицание запрещённой информацией, ст. 15.1 ФЗ-149).
     */
    private const HIV_TOPIC_SLUGS = [
        'vic-infekciia',
        'voprosy-po-proektu-izosfera-i-parallelnymi-miry',
        'socialnye-iavleniia-zemli-civilizaciia-zemli',
    ];

    // Формулировки: «оценочные суждения» — категория из практики по
    // ст. 152 ГК (Пленум ВС № 3 от 24.02.2005, мнения не опровергаются
    // судом); «не является консультацией…» — назначение публикации,
    // а не модальность; БЕЗ утверждений о факте вроде «не содержат
    // пропаганды» (владелец не может это гарантировать, а при споре
    // такая фраза работает против него).
    private const MEDICAL_HEAD = 'Сообщения в этой теме — личные мнения и оценочные суждения их авторов, '
        .'участников форума 2012–2019 годов, и не являются позицией владельца сайта. '
        .'Владелец сайта не проверял и не подтверждает достоверность изложенных сведений. '
        .'Тема публикуется в информационных и архивных целях как исторический документ дискуссии; '
        .'она не является медицинской консультацией или рекомендацией и не может использоваться '
        .'как руководство к действию. Сведения о здоровье, заболеваниях и способах лечения могут '
        .'не соответствовать современным научным данным — по вопросам здоровья обращайтесь к врачу.';

    private const HIV_NOTE = ' Отдельные сообщения ставят под сомнение существование ВИЧ-инфекции; '
        .'такие утверждения ошибочны и противоречат установленным научным данным: ВИЧ существует, '
        .'без лечения приводит к СПИДу, а своевременная антиретровирусная терапия сохраняет здоровье '
        .'и жизнь. Отказ от назначенного врачом обследования и лечения опасен для жизни.';

    private const MEDICAL_TAIL = ' Если вы считаете, что публикация нарушает ваши права, '
        .'воспользуйтесь контактом на странице «Правовая информация».';

    public function up(): void
    {
        Schema::table('forum_topics', function (Blueprint $table) {
            $table->text('disclaimer')->nullable()->after('posts_count');
        });

        DB::table('forum_topics')
            ->whereIn('slug', self::MEDICAL_TOPIC_SLUGS)
            ->update(['disclaimer' => self::MEDICAL_HEAD.self::MEDICAL_TAIL]);

        DB::table('forum_topics')
            ->whereIn('slug', self::HIV_TOPIC_SLUGS)
            ->update(['disclaimer' => self::MEDICAL_HEAD.self::HIV_NOTE.self::MEDICAL_TAIL]);
    }

    public function down(): void
    {
        Schema::table('forum_topics', function (Blueprint $table) {
            $table->dropColumn('disclaimer');
        });
    }
};
