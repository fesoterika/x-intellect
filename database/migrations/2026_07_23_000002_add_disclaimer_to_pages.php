<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Дисклеймер материала: свободный текст, выводится внизу страницы под
// плашкой «Нашли ошибку?» (по образцу forum_topics.disclaimer).
// Бэкфилл — по аудиту материалов 23.07.2026 (плотность и контекст
// медицинской лексики, см. сеанс аудита): материалы, где здоровье,
// болезни, целительство или воздействующие практики — существенная
// часть содержания. ВИЧ-отрицания в материалах не найдено (2 нейтральных
// упоминания), усиленный вариант приписки не требуется.
// ВАЖНО: обновляется ТОЛЬКО колонка disclaimer и только по slug —
// содержимое материалов (body/title) не трогаем: на проде есть ручные
// правки, которые нужно сохранить.
return new class extends Migration
{
    /**
     * Материалы с существенным «медицинским» содержанием.
     * Сгруппированы по происхождению; slug уникален в pages,
     * поэтому обновление идёт без привязки к разделу.
     */
    private const MEDICAL_PAGE_SLUGS = [
        // Проект «Целительство» — раздел целиком
        '297',
        'questions',
        // Проект «Человек — Земля — Космос» — оздоровительные практики, раздел целиком
        'proekt-chelovek-zemlya-kosmos-2014',
        'proekt-chelovek-zemlya-kosmos-2014-ch-2',
        'proekt-chelovek-zemlya-kosmos-2014-ch-3',
        'proekt-chelovek-zemlya-kosmos-2014-ch-4',
        'proekt-chelovek-zemlya-kosmos-2014-ch-5',
        'proekt-chelovek-zemlya-kosmos-2014-upravlenie-sobstvennoj-e-nergetikoj-ch-1',
        'proekt-chelovek-zemlya-kosmos-2014-upravlenie-sobstvennoj-e-nergetikoj-ch-2',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-3',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-4',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-5',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-6',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-7',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-otvety-sil-na-prakticheskie-voprosy',
        // Курсы «коррекции» и их планы тренингов (проработка заболеваний)
        'karmiceskaia-korrekciia',
        'psixo-emocionalnaia-i-energeticeskaia-korrekciia',
        'plan-treninga-karmiceskaia-korrekciia',
        'plan-treninga-psixo-emocionalnaia-i-energeticeskaia-korrekciia',
        // Вики: лечение, точки, целительство, техники с «диагностикой организма»
        'lecenie-s-pomoshhiu-psixo-bioenergeticeskogo-vozdeistviia-na-bioaktivnye-tocki',
        'biologiceski-aktivnye-tocki',
        'celitelstvo',
        'razvitie-energoinformacionnogo-vospriiatiia',
        'texnika-astralnoi-sborki-obolocecnogo-dvoinika',
        'karma',
        // Осознанные сновидения: утверждения о болезнях и «лечении» через ОС
        'osoznannye-snovideniia-i-vnetelesnyi-opyt-nacalo-temy',
        'osoznannye-snovideniia-i-vnetelesnyi-opyt-cast-1',
        'osoznannye-snovideniia-cast-3-dalnii-kosmos',
        // Личные консультации — разбор проблем и здоровья конкретных людей
        'licnye-konsultacii',
        'licnaia-konsultaciia-20100612',
        'licnaia-konsultaciia-20100722',
        'licnaia-konsultaciia-20100924',
        'licnaia-konsultaciia-20110421',
        'licnaia-konsultaciia-20110525',
        'licnaia-konsultaciia-20120524',
        'licnaia-konsultaciia-20120706',
        'licnaia-konsultaciia-20130312',
        'licnaia-konsultaciia-20090824a',
        'licnaia-konsultaciia-20090824b',
        'licnaia-konsultaciia-20090824c',
        // Сеансы с существенным содержанием о болезнях/лечении
        'seans-s-silami-20081026',
        'seans-s-silami-20090119',
        'seans-s-silami-20090719',
        'seans-s-silami-20100606',
        'seans-s-silami-20100620',
        'seans-s-silami-20100718',
        'seans-s-silami-20110508',
        'seans-s-silami-20121201',
        'seans-s-silami-20121217',
        'seans-s-silami-20130118',
        'seans-s-silami-20130119',
        'seans-s-silami-20130310',
        'seans-s-silami-20130513',
        'seans-s-silami-20130630',
        'seans-s-silami-20131123',
        'obshhaia-informaciia-20080318b',
        'proekt-izosfera-i-parallel-ny-e-miry-4',
        // Статьи: практики и псевдомедицинские утверждения
        'lotos',
        'tema-o-castote-sumana-i-ne-tolko-o-nei',
    ];

    // Формулировки — как у форумных дисклеймеров (юр. переработка 23.07.2026):
    // «оценочные суждения» — категория из практики по ст. 152 ГК (Пленум ВС № 3
    // от 24.02.2005), назначение публикации вместо утверждений о факте.
    private const MEDICAL_TEXT = 'Материал из архива проекта публикуется в информационных и архивных целях. '
        .'Содержащиеся в нём утверждения — взгляды и оценочные суждения его авторов; '
        .'владелец сайта не проверял и не подтверждает их достоверность. '
        .'Материал не является медицинской консультацией или рекомендацией и не может '
        .'использоваться как руководство к действию: описанные практики и представления '
        .'о здоровье, заболеваниях и способах их лечения не имеют научно подтверждённой '
        .'эффективности и могут не соответствовать современным медицинским данным. '
        .'По вопросам здоровья обращайтесь к врачу. '
        .'Если вы считаете, что публикация нарушает ваши права, воспользуйтесь '
        .'контактом на странице «Правовая информация».';

    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->text('disclaimer')->nullable()->after('excerpt');
        });

        DB::table('pages')
            ->whereIn('slug', self::MEDICAL_PAGE_SLUGS)
            ->update(['disclaimer' => self::MEDICAL_TEXT]);
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('disclaimer');
        });
    }
};
