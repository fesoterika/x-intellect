<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Services\MediaWikiArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Слияние навигационных страниц «Сеансы {период}» и добор пунктов.
 *
 *   php artisan navigation:merge-sessions {--dry}
 *
 * На старой вики период 1991–2008 и годы 2009/2010 существовали и как сводные
 * страницы («Сеансы 1991 - 2008», «Сеансы 2009 - 2010»), и как погодовые
 * дубли — с пересекающимися, но неполными списками. Здесь пересекающиеся
 * периоды сливаются в одну сводную страницу без потери пунктов, старые адреса
 * получают 301, а в каждый период добавляются недостающие пункты: сеансы,
 * появившиеся на сайте позже, и материалы «Сферы Разума» (с пометкой).
 *
 * Правки точечные: существующие пункты, картинки и оформление не трогаются —
 * недостающее добавляется в конец списка. Идемпотентна.
 */
class MergeSessionNavigation extends Command
{
    protected $signature = 'navigation:merge-sessions {--dry}';

    protected $description = 'Объединяет дубли периодов «Сеансы …», добавляет недостающие пункты и Сферу Разума';

    /**
     * Период → [канон-заголовок, годы, заголовки-дубли (сливаются и удаляются)].
     */
    private const PERIODS = [
        ['Сеансы 1991 - 2008', [1991, 2008], ['Сеансы 1991', 'Сеансы 1997 - 2008', 'Сеансы 2007', 'Сеансы 2008']],
        ['Сеансы 2009 - 2010', [2009, 2010], ['Сеансы 2009', 'Сеансы 2010']],
        ['Сеансы 2011', [2011, 2011], []],
        ['Сеансы 2012', [2012, 2012], []],
        ['Сеансы 2013', [2013, 2013], []],
        ['Сеансы 2014', [2014, 2014], []],
        ['Сеансы 2015', [2015, 2015], []],
        ['Сеансы 2016', [2016, 2016], []],
        ['Сеансы 2017', [2017, 2017], []],
    ];

    public function handle(MediaWikiArchive $mw): int
    {
        $dry = (bool) $this->option('dry');
        $added = 0;
        $merged = 0;

        foreach (self::PERIODS as [$canonTitle, [$from, $to], $dupTitles]) {
            $canon = Page::where('title', $canonTitle)->first();
            if (! $canon) {
                $this->warn("Нет канонической: {$canonTitle}");

                continue;
            }
            $dups = collect($dupTitles)->map(fn ($t) => Page::where('title', $t)->first())->filter();

            // Даты, уже присутствующие где-либо в теле канона
            $present = $this->datesIn($canon->body);

            // Кандидаты: страницы-сеансы периода (обычные + Сфера) без пункта в каноне
            $items = [];
            foreach ($this->sessionPages($from, $to) as $page) {
                $key = $this->dateOf($page->title);
                if ($key === null || in_array($key, $present, true)) {
                    continue;
                }
                $present[] = $key;
                $items[$key] = $this->listItem($page);
            }

            // Пункты из дублей, которых ещё нет (в т.ч. сеансы без страницы)
            foreach ($dups as $dup) {
                foreach ($this->plainItems($dup->body) as $key => $text) {
                    // PHP приводит числовой строковый ключ массива к int, а
                    // $present — строки; strict-сравнение мимо. Приводим к строке.
                    $key = (string) $key;
                    if (in_array($key, $present, true)) {
                        continue;
                    }
                    $present[] = $key;
                    $items[$key] = $this->linkedItem($key, $text);
                }
            }

            ksort($items);
            if ($items) {
                $body = $this->appendItems($canon->body, array_values($items));
                $body = $this->dropMetaLinks($body, $dupTitles);
                $this->line(sprintf('[%s] +%d пунктов', $canonTitle, count($items)));
                foreach ($items as $li) {
                    $this->line('     '.Str::limit(trim(strip_tags($li)), 70));
                }
                $added += count($items);
                if (! $dry) {
                    $canon->body = $body;
                    $canon->save();
                }
            }

            // Слияние дублей: 301 на канон, затем удаление
            foreach ($dups as $dup) {
                foreach ($mw->oldWikiPaths($dup->title) as $path) {
                    if (! $dry) {
                        Redirect::updateOrCreate(
                            ['from_path' => $path],
                            ['to_url' => $canon->url(), 'status_code' => 301, 'comment' => 'Слито: '.$canonTitle],
                        );
                    }
                }
                $this->line("     дубль удалён → 301: {$dup->title}");
                $merged++;
                if (! $dry) {
                    $dup->delete();
                }
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Добавлено пунктов: {$added}. Слито дублей: {$merged}.");

        return self::SUCCESS;
    }

    /** Страницы-сеансы периода: скрытые из списков стенограммы + Сфера. */
    private function sessionPages(int $from, int $to): \Illuminate\Support\Collection
    {
        return Page::whereIn('source_type', ['archive_wiki', 'archive_sferarazuma'])
            ->where('is_listed', false)
            ->where(fn ($q) => $q->where('title', 'like', 'Сеанс%')
                ->orWhere('title', 'like', 'Совместная конференция%')
                ->orWhere('title', 'like', 'Встреча%')
                ->orWhere('title', 'like', 'Лекция%')
                ->orWhere('source_type', 'archive_sferarazuma'))
            ->get()
            ->filter(function ($p) use ($from, $to) {
                $key = $this->dateOf($p->title);

                return $key !== null && (int) substr($key, 0, 4) >= $from && (int) substr($key, 0, 4) <= $to;
            })
            ->sortBy(fn ($p) => $this->dateOf($p->title));
    }

    /** Пункт-ссылка на существующую страницу; для Сферы — пометка в скобках. */
    private function listItem(Page $page): string
    {
        $label = $page->title;
        if ($page->source_type === 'archive_sferarazuma') {
            $label .= ' (Сфера Разума)';
        }

        return '<li><a href="'.e($page->url()).'"><strong>'.e($label).'</strong></a></li>';
    }

    /** Пункт из строки-дубля: если есть страница по дате — со ссылкой. */
    private function linkedItem(string $key, string $text): string
    {
        $page = Page::where('title', 'like', '%'.$key.'%')
            ->whereIn('source_type', ['archive_wiki', 'archive_sferarazuma'])->first();
        if ($page) {
            return '<li><a href="'.e($page->url()).'"><strong>'.e($text).'</strong></a></li>';
        }

        return '<li><strong>'.e($text).'</strong></li>';
    }

    /** Даты (ГГГГММДД[буква]) во всём теле. */
    private function datesIn(?string $body): array
    {
        preg_match_all('/((?:19|20)\d{6}[a-zA-Z]?)/', (string) $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** Первая дата из строки/заголовка. */
    private function dateOf(string $text): ?string
    {
        return preg_match('/((?:19|20)\d{6}[a-zA-Z]?)/', $text, $m) ? $m[1] : null;
    }

    /**
     * Пункты дубля как «дата → текст» (строки-сеансы, не мета-ссылки на годы).
     *
     * @return array<string, string>
     */
    private function plainItems(?string $body): array
    {
        $text = html_entity_decode(preg_replace('/<[^>]+>/', "\n", (string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            $key = $this->dateOf($line);
            if ($key === null || preg_match('/^Сеансы\s/u', $line)) {
                continue; // мета-пункт «Сеансы 2007» — не сеанс
            }
            if (! isset($out[$key]) || mb_strlen($line) > mb_strlen($out[$key])) {
                $out[$key] = $line;
            }
        }

        return $out;
    }

    /** Добавляет пункты перед последним </ul>; если списка нет — оборачивает в новый. */
    private function appendItems(string $body, array $lis): string
    {
        $chunk = implode('', $lis);
        $pos = mb_strrpos($body, '</ul>');
        if ($pos !== false) {
            return mb_substr($body, 0, $pos).$chunk.mb_substr($body, $pos);
        }

        return $body.'<ul>'.$chunk.'</ul>';
    }

    /** Убирает мета-ссылки на удаляемые погодовые дубли («Сеансы 2007»). */
    private function dropMetaLinks(string $body, array $dupTitles): string
    {
        foreach ($dupTitles as $t) {
            // <li>…текст «Сеансы NNNN»…</li> целиком
            $body = preg_replace('#<li>(?:(?!</li>).)*?'.preg_quote(e($t), '#').'.*?</li>#su', '', $body);
        }

        return $body;
    }
}
