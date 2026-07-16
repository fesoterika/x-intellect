<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PageRevision;
use App\Services\ExcerptMaker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Дозаполнение метаданных материалов (одноразовый прогон, идемпотентен):
 *
 *   php artisan content:backfill [--dry] [--wipe-revisions]
 *
 *  - archived_at: из метки времени Wayback в source_url
 *    (…/web/2015/… → 2015-01-01, …/web/20240712163937/… → 2024-07-12).
 *  - excerpt: первые предложения тела (до ~200 символов).
 *  Заполняются ТОЛЬКО пустые поля — ручные значения не перезаписываются.
 *  --wipe-revisions: очистить историю изменений (page_revisions) целиком.
 */
class BackfillContentMeta extends Command
{
    protected $signature = 'content:backfill {--dry} {--wipe-revisions}';

    protected $description = 'Заполнить пустые archived_at (из source_url) и excerpt (из тела); опционально очистить ревизии';

    public function __construct(private ExcerptMaker $excerpts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $dates = 0;
        $excerpts = 0;

        foreach (Page::all() as $page) {
            $changed = false;

            if ($page->archived_at === null && ($date = $this->waybackDate($page->source_url))) {
                $page->archived_at = $date;
                $dates++;
                $changed = true;
            }

            if (blank($page->excerpt) && ($excerpt = $this->excerpts->fromBody($page->body)) !== '') {
                $page->excerpt = $excerpt;
                $excerpts++;
                $changed = true;
            }

            if ($changed && ! $dry) {
                // тихо: тело не меняется — ни ревизий, ни повторного рендера
                $page->saveQuietly();
            }
        }

        $this->info(($dry ? '[dry] ' : '')."Дат заполнено: {$dates}, анонсов заполнено: {$excerpts}.");

        if ($this->option('wipe-revisions')) {
            $count = PageRevision::count();
            if (! $dry) {
                PageRevision::query()->delete();
            }
            $this->info(($dry ? '[dry] ' : '')."История изменений очищена: {$count} ревизий.");
        }

        return self::SUCCESS;
    }

    /** Метка времени Wayback из source_url: /web/<4-14 цифр>/ → дата или null. */
    private function waybackDate(?string $url): ?Carbon
    {
        if (! $url || ! preg_match('#//web\.archive\.org/web/(\d{4,14})/#', $url, $m)) {
            return null;
        }

        $ts = $m[1];
        $year = (int) substr($ts, 0, 4);
        $month = strlen($ts) >= 6 ? max(1, min(12, (int) substr($ts, 4, 2))) : 1;
        $day = strlen($ts) >= 8 ? max(1, min(31, (int) substr($ts, 6, 2))) : 1;

        if ($year < 1996 || $year > (int) now()->format('Y')) {
            return null;
        }

        return Carbon::createFromDate($year, $month, $day);
    }
}
