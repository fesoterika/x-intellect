<?php

namespace App\Console\Commands;

use App\Models\ForumTopic;
use App\Models\Page;
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

        foreach (Page::where('status', 'published')->get() as $page) {
            $urls[] = $page->url();
        }

        foreach (\App\Models\Section::all() as $section) {
            $urls[] = $section->url();
        }

        $urls[] = '/forum';
        foreach (ForumTopic::all() as $topic) {
            $urls[] = $topic->url();
        }

        $urls[] = '/glossary';

        return $urls;
    }
}
