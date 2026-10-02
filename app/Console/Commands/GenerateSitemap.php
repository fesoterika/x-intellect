<?php

namespace App\Console\Commands;

use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Генерация статических Sitemap по опубликованному содержимому.
 * lastmod обозначает последнюю сохранённую правку, а не время пересборки.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Сгенерировать sitemap.xml по опубликованным страницам';

    public function handle(): int
    {
        $base = rtrim(config('app.url'), '/');
        $sections = Section::where('is_visible', true)->with(['parent', 'children'])->get();
        $pages = Page::published()->with(['section.parent', 'media'])
            ->get(['id', 'section_id', 'slug', 'page_type', 'is_listed', 'updated_at']);
        $terms = GlossaryTerm::get(['id', 'slug', 'updated_at']);
        $topics = ForumTopic::withMax('posts', 'updated_at')->get();
        $topicDates = $topics->mapWithKeys(fn ($topic) => [
            $topic->id => $this->latestModification([$topic->updated_at, $topic->posts_max_updated_at]),
        ]);

        // У главной и списков нет своей записи Page: учитываем даты
        // публичных записей, из которых формируется их содержимое.
        $homeDate = $this->latestModification([
            ...$sections->pluck('updated_at'),
            ...$pages->pluck('updated_at'),
            ...$terms->pluck('updated_at'),
            ...$topicDates->values(),
        ]);
        // Ключом служит полный URL: один адрес не попадёт в карту дважды.
        $urls = [$base.'/' => ['loc' => $base.'/', 'lastmod' => $homeDate, 'priority' => '1.0']];

        foreach ($sections as $section) {
            $sectionIds = $section->isRoot()
                ? $section->children->pluck('id')->push($section->id)
                : collect([$section->id]);
            $listedPages = $pages->whereIn('section_id', $sectionIds)->where('is_listed', true);
            $loc = $base.$section->url();
            $urls[$loc] = [
                'loc' => $loc,
                'lastmod' => $this->latestModification([
                    $section->updated_at,
                    ...$listedPages->pluck('updated_at'),
                ]),
                'priority' => '0.8',
            ];
        }

        if ($terms->isNotEmpty()) {
            $loc = $base.'/glossary';
            $urls[$loc] = [
                'loc' => $loc,
                'lastmod' => $this->latestModification($terms->pluck('updated_at')),
                'priority' => '0.7',
            ];
            foreach ($terms as $term) {
                $loc = $base.$term->url();
                $urls[$loc] = ['loc' => $loc, 'lastmod' => $term->updated_at?->toAtomString(), 'priority' => '0.5'];
            }
        }

        $mediaUrls = [];
        foreach ($pages as $page) {
            $entry = [
                'loc' => $base.$page->url(),
                'lastmod' => $page->updated_at?->toAtomString(),
                'priority' => $page->page_type === 'author' ? '0.9' : '0.7',
            ];
            $urls[$entry['loc']] = $entry;
            if ($page->media->whereIn('type', ['audio', 'pdf'])->isNotEmpty()) {
                $mediaUrls[$entry['loc']] = $entry;
            }
        }

        if ($topics->isNotEmpty()) {
            $loc = $base.'/forum';
            $urls[$loc] = [
                'loc' => $loc,
                'lastmod' => $this->latestModification($topicDates->values()),
                'priority' => '0.6',
            ];
            foreach ($topics as $topic) {
                $loc = $base.$topic->url();
                $urls[$loc] = ['loc' => $loc, 'lastmod' => $topicDates[$topic->id], 'priority' => '0.5'];
            }
        }

        $this->writeXml('sitemap.xml', $this->buildXml($urls));
        $this->info('public/sitemap.xml: '.count($urls).' URL');

        if ($mediaUrls !== []) {
            $this->writeXml('sitemap-media.xml', $this->buildXml($mediaUrls));
            $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
                ."  <sitemap><loc>{$base}/sitemap.xml</loc></sitemap>\n"
                ."  <sitemap><loc>{$base}/sitemap-media.xml</loc></sitemap>\n"
                .'</sitemapindex>'."\n";
            $this->writeXml('sitemap-index.xml', $index);
            $this->info('public/sitemap-media.xml: '.count($mediaUrls).' URL (+ sitemap-index.xml)');
        }

        return self::SUCCESS;
    }

    protected function latestModification(iterable $dates): ?string
    {
        $latest = null;
        foreach ($dates as $date) {
            if ($date === null || $date === '') {
                continue;
            }
            $parsed = Carbon::parse($date, config('app.timezone', 'UTC'));
            if ($latest === null || $parsed->greaterThan($latest)) {
                $latest = $parsed;
            }
        }

        return $latest?->toAtomString();
    }

    protected function writeXml(string $name, string $contents): void
    {
        $path = public_path($name);
        $temporary = tempnam(dirname($path), '.sitemap-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create temporary Sitemap');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Cannot write Sitemap');
            }
            chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0644);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Cannot replace Sitemap');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function buildXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'
                .($url['lastmod'] ? '<lastmod>'.$url['lastmod'].'</lastmod>' : '')
                .'<priority>'.$url['priority'].'</priority></url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
