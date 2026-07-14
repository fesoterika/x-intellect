<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Console\Command;

/**
 * Генерация статического public/sitemap.xml (Этап 5 плана).
 * Запускается по cron (Laravel Scheduler) и по событию публикации
 * страницы через очередь (см. App\Jobs\RegenerateSitemap).
 * При наличии аудио/книг дополнительно создаются sitemap-media.xml
 * и объединяющий sitemap-index.xml.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Сгенерировать sitemap.xml по опубликованным страницам';

    public function handle(): int
    {
        $base = rtrim(config('app.url'), '/');

        $urls = [
            ['loc' => $base.'/', 'lastmod' => now()->toAtomString(), 'priority' => '1.0'],
        ];

        foreach (Section::where('is_visible', true)->get() as $section) {
            $urls[] = [
                'loc' => $base.$section->url(),
                'lastmod' => $section->updated_at?->toAtomString(),
                'priority' => '0.8',
            ];
        }

        $mediaUrls = [];

        Page::published()->with(['section', 'media'])->chunk(200, function ($pages) use (&$urls, &$mediaUrls, $base) {
            foreach ($pages as $page) {
                $entry = [
                    'loc' => $base.$page->url(),
                    'lastmod' => $page->updated_at?->toAtomString(),
                    'priority' => $page->page_type === 'author' ? '0.9' : '0.7',
                ];

                $urls[] = $entry;

                // Страницы с аудио/книгами — в отдельный медиа-sitemap
                if ($page->media->whereIn('type', ['audio', 'pdf'])->isNotEmpty()) {
                    $mediaUrls[] = $entry;
                }
            }
        });

        // Архив форума: список + темы (только чтение, phpBB-слепок 2015)
        if (\App\Models\ForumTopic::query()->exists()) {
            $urls[] = ['loc' => $base.'/forum', 'lastmod' => now()->toAtomString(), 'priority' => '0.6'];
            foreach (\App\Models\ForumTopic::all() as $topic) {
                $urls[] = [
                    'loc' => $base.$topic->url(),
                    'lastmod' => ($topic->last_posted_at ?? $topic->updated_at)?->toAtomString(),
                    'priority' => '0.5',
                ];
            }
        }

        file_put_contents(public_path('sitemap.xml'), $this->buildXml($urls));
        $this->info('public/sitemap.xml: '.count($urls).' URL');

        if ($mediaUrls !== []) {
            file_put_contents(public_path('sitemap-media.xml'), $this->buildXml($mediaUrls));

            $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
                ."  <sitemap><loc>{$base}/sitemap.xml</loc></sitemap>\n"
                ."  <sitemap><loc>{$base}/sitemap-media.xml</loc></sitemap>\n"
                .'</sitemapindex>'."\n";

            file_put_contents(public_path('sitemap-index.xml'), $index);
            $this->info('public/sitemap-media.xml: '.count($mediaUrls).' URL (+ sitemap-index.xml)');
        }

        return self::SUCCESS;
    }

    protected function buildXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'
                .'<loc>'.e($url['loc']).'</loc>'
                .($url['lastmod'] ? '<lastmod>'.$url['lastmod'].'</lastmod>' : '')
                .'<priority>'.$url['priority'].'</priority>'
                .'</url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
