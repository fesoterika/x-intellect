<?php

namespace App\Console\Commands;

use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use App\Services\IndexNow;
use Illuminate\Console\Command;

/**
 * Ручная подача адресов в IndexNow.
 *
 *   indexnow:submit --all              весь сайт (материалы, разделы, форум)
 *   indexnow:submit /wiki/karma /forum конкретные адреса
 *
 * Обычные правки уходят сами из PageObserver; команда нужна для первичной
 * подачи после запуска и для разовых массовых изменений.
 */
class IndexNowSubmit extends Command
{
    protected $signature = 'indexnow:submit {urls?* : Адреса или пути} {--all : Подать весь сайт}';

    protected $description = 'Подать адреса в IndexNow (Яндекс, Bing)';

    public function handle(IndexNow $indexNow): int
    {
        if (! $indexNow->enabled()) {
            $this->error('INDEXNOW_KEY не задан — отправка выключена.');

            return self::FAILURE;
        }

        $urls = $this->option('all')
            ? $this->allUrls()
            : (array) $this->argument('urls');

        if ($urls === []) {
            $this->error('Нечего подавать: укажите адреса или --all.');

            return self::FAILURE;
        }

        $sent = $indexNow->submit($urls);

        $this->info("Подано адресов: {$sent} из ".count($indexNow->normalize($urls)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function allUrls(): array
    {
        $urls = ['/'];

        // Состав повторяет GenerateSitemap: подавать в поиск то, чего нет в
        // карте сайта, — значит разойтись с ней. Скрытые разделы поэтому
        // отсеиваются, а термины глоссария, наоборот, подаются: каждый из
        // них самостоятельный адрес /glossary?term=<slug>.
        foreach (Section::where('is_visible', true)->get() as $section) {
            $urls[] = $section->url();
        }

        $urls[] = '/glossary';
        foreach (GlossaryTerm::all() as $term) {
            $urls[] = $term->url();
        }

        Page::published()->chunk(200, function ($pages) use (&$urls) {
            foreach ($pages as $page) {
                $urls[] = $page->url();
            }
        });

        if (ForumTopic::query()->exists()) {
            $urls[] = '/forum';
            foreach (ForumTopic::all() as $topic) {
                $urls[] = $topic->url();
            }
        }

        return $urls;
    }
}
