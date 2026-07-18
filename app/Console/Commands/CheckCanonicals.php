<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;

/**
 * Сверка seo.canonical с фактическими адресами страниц.
 *
 *   php artisan seo:canonical [--fix]
 *
 * Автозаполнения canonical больше нет (шаблоны строят его от APP_URL на лету),
 * но запечённые значения ещё встречаются: ручные из админ-формы и наследие
 * прежнего автозаполнения. Запечённый canonical после переезда страницы в
 * другой корневой раздел остаётся на прежнем адресе и спорит с собственным
 * 301: поисковик получает канонический адрес, который сам же и редиректит.
 * PageObserver чинит это для новых переездов — команда подчищает накопленное.
 *
 * Хост берётся из APP_URL и НЕ меняется: команда правит путь. Смена хоста
 * (dev → прод) — отдельная операция.
 *
 * Правится только то, что выставлено автоматически (хост совпадает с APP_URL).
 * Canonical с чужим хостом — осознанный выбор редактора, его не трогаем.
 */
class CheckCanonicals extends Command
{
    protected $signature = 'seo:canonical {--fix}';

    protected $description = 'Сверка seo.canonical с фактическими адресами страниц';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $base = rtrim(config('app.url'), '/');
        $host = parse_url($base, PHP_URL_HOST);

        $stale = [];
        $foreign = [];
        $ok = 0;

        foreach (Page::with('section.parent')->get() as $page) {
            $canonical = $page->seoValue('canonical');
            if (blank($canonical)) {
                continue;
            }

            $expected = $base.$page->url();
            if ($canonical === $expected) {
                $ok++;

                continue;
            }

            if (parse_url($canonical, PHP_URL_HOST) !== $host) {
                $foreign[] = [$page, $canonical];

                continue;
            }

            $stale[] = [$page, $canonical, $expected];
        }

        $this->info("Страниц с canonical: {$ok} верных, ".count($stale).' устаревших, '.count($foreign).' с чужим хостом.');

        if ($stale !== []) {
            $this->newLine();
            $this->comment('Устаревшие (указывают не на свой адрес):');
            foreach (array_slice($stale, 0, 40) as [$page, $canonical, $expected]) {
                $this->line('  '.mb_strimwidth($page->title, 0, 34, '…'));
                $this->line('      было:  '.$canonical);
                $this->line('      надо:  '.$expected);
            }
            if (count($stale) > 40) {
                $this->line('  … ещё '.(count($stale) - 40));
            }
        }

        if ($foreign !== []) {
            $this->newLine();
            $this->comment('Чужой хост — задан вручную, не трогаем:');
            foreach ($foreign as [$page, $canonical]) {
                $this->line('  '.mb_strimwidth($page->title, 0, 34, '…').' → '.$canonical);
            }
        }

        if (! $fix) {
            if ($stale !== []) {
                $this->newLine();
                $this->comment('Исправить: --fix');
            }

            return self::SUCCESS;
        }

        foreach ($stale as [$page, , $expected]) {
            $seo = $page->seo ?? [];
            $seo['canonical'] = $expected;
            $page->seo = $seo;
            // тихо: тело не меняется — ни ревизии, ни повторного рендера
            $page->saveQuietly();
        }

        $this->newLine();
        $this->info('Исправлено: '.count($stale).'.');

        return self::SUCCESS;
    }
}
