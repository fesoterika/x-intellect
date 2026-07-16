<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\AudioLibrary;
use App\Services\MediaWikiArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Восстановление сеансов, которых нет ни в слепке, ни в веб-архиве.
 *
 *   php artisan sessions:restore-missing {архив} [--dry] [--audio-dir=*]
 *
 * Страницы-указатели («Сеансы 2009», «Сеансы 2010»…) перечисляют сеансы, у
 * которых на старой вики были живые ссылки, но сами страницы не сохранились:
 * в слепок 2015 они не попали, а веб-архив их не снимал (404 на всех снимках).
 * Стенограммы утрачены — зато аудиозаписи лежат в папках архива проекта.
 *
 * Команда берёт из перечней названия сеансов без страницы, ищет запись по дате
 * в библиотеке аудио и создаёт страницу-черновик: таблица с датой, честная
 * пометка об отсутствии стенограммы и сама запись. Идемпотентна.
 */
class RestoreMissingSessions extends Command
{
    protected $signature = 'sessions:restore-missing {archive} {--dry} {--audio-dir=*}';

    protected $description = 'Создаёт страницы сеансов, утраченных в архивах, из аудиозаписей в папках';

    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public function handle(MediaWikiArchive $mw): int
    {
        $base = rtrim($this->argument('archive'), '/');
        $roots = array_values(array_filter(
            array_merge([$base], (array) ($this->option('audio-dir') ?: config('archive.audio_dirs', []))),
            'is_dir'
        ));
        $library = (new AudioLibrary($roots))->build();
        $this->info('mp3 в библиотеке: '.$library->count());

        $section = Section::where('slug', 'seansy')->first() ?? Section::where('slug', 'wiki')->first();
        if (! $section) {
            $this->error('Нет раздела для сеансов.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $created = 0;
        $noAudio = [];

        foreach ($this->missingTitles() as $title => $indexPage) {
            $key = AudioLibrary::dateKeyOf($title);
            $tracks = $key ? $library->byDateKey($key) : [];
            if (! $tracks) {
                $noAudio[] = $title.' (из «'.$indexPage.'»)';

                continue;
            }

            $this->line(sprintf('+ %-32s ← %s (%.0f мин, %s)', $title,
                Str::limit($tracks[0]['name'], 42), $tracks[0]['duration'], $tracks[0]['source']));
            $created++;

            if ($dry) {
                continue;
            }

            $page = Page::create([
                'section_id' => $section->id,
                'title' => $title,
                'slug' => $mw->uniqueSlug($title, Page::class),
                'body' => $this->body($key),
                'status' => 'draft',
                'is_listed' => false, // стенограммы скрыты из списков, как остальные
                'source_type' => 'archive_wiki',
                'source_url' => 'https://web.archive.org/web/2015/http://www.x-intellect.org/wiki/index.php?title='
                    .rawurlencode(str_replace(' ', '_', $title)),
            ]);

            foreach ($mw->oldWikiPaths($title) as $from) {
                Redirect::updateOrCreate(
                    ['from_path' => $from],
                    ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Архив вики: '.Str::limit($title, 50)],
                );
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Создано страниц сеансов: {$created}.");
        if ($noAudio) {
            $this->warn('Без записи в папках (создать не из чего): '.implode('; ', $noAudio));
        }
        $this->comment('Аудио привяжет import:offline-audio (сопоставление по дате в заголовке).');

        return self::SUCCESS;
    }

    /**
     * Названия сеансов, перечисленных в страницах-указателях, но без страницы.
     *
     * Берём из тел указателей: ссылки там срезаны импортом, но текст остался —
     * это и есть перечень сеансов за год.
     *
     * @return array<string, string> название сеанса → указатель, где встретилось
     */
    private function missingTitles(): array
    {
        $out = [];
        $indexes = Page::where('title', 'like', 'Сеансы%')->orderBy('title')->get();

        foreach ($indexes as $index) {
            // Теги заменяем ПРОБЕЛОМ, а не вырезаем: соседние ссылки перечня
            // иначе слипаются («…20111207Сеанс с силами 20111213»), и в дату
            // затягивается первая буква следующего пункта.
            $text = preg_replace('/<[^>]+>/', ' ', (string) $index->body);
            $text = preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (! preg_match_all('/Сеанс\s+с\s+[Сс]илами\s+(\d{8}[a-zA-Z]?)(?![\w\d])/u', $text, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as [$title, $dateKey]) {
                $title = trim($title);
                if (isset($out[$title])) {
                    continue;
                }
                // Сеанс уже представлен в архиве? Учитываем и вариант под другим
                // названием: перечень зовёт «Сеанс с Силами 20120726», а в архиве
                // это 20120726a…d — четыре отдельные записи того дня.
                if (Page::where('title', 'like', '%'.$dateKey.'%')->exists()) {
                    continue;
                }
                $out[$title] = $index->title;
            }
        }

        return $out;
    }

    /** Тело страницы в том же виде, что у остальных сеансов архива. */
    private function body(string $dateKey): string
    {
        $year = (int) substr($dateKey, 0, 4);
        $month = (int) substr($dateKey, 4, 2);
        $day = (int) substr($dateKey, 6, 2);
        $date = sprintf('%d %s %d года', $day, self::MONTHS[$month] ?? '', $year);

        return '<table><tbody>'
            .'<tr><td><strong>Дата:</strong></td><td>'.$date.'</td></tr>'
            .'<tr><td><strong>Силы</strong></td><td>нет сведений</td></tr>'
            .'<tr><td><strong>Посредник</strong></td><td>нет сведений</td></tr>'
            .'</tbody></table>'
            .'<p><strong>Стенограмма</strong></p>'
            .'<p>Стенограмма сеанса не сохранилась: страницы нет ни в архиве сайта 2015 года, '
            .'ни в веб-архиве. Сохранилась аудиозапись из архива проекта — она ниже.</p>';
    }
}
