<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Console\Command;

/**
 * Сверка таблицы redirects с фактическими адресами нового сайта.
 *
 *   php artisan redirects:check [--fix]
 *
 * Импортёры наполняют redirects по ходу переноса архива, но адреса страниц
 * потом меняются (перенос между корневыми разделами), и записи протухают
 * молча — посетитель получает 404 там, где обещан 301.
 *
 * Чинятся (--fix) только однозначные случаи:
 *   - цель переехала: страница с тем же slug живёт по другому адресу;
 *   - цепочка: to_url сам является from_path — схлопываем в один хоп
 *     (лишний хоп теряет вес ссылки и замедляет переход).
 * Остальное — в отчёт: где нужен человек, команда не угадывает.
 *
 * Гонять ПОСЛЕ импортов: до них «цели нет» — обычное дело.
 */
class CheckRedirects extends Command
{
    protected $signature = 'redirects:check {--fix}';

    protected $description = 'Сверка редиректов с фактическими адресами нового сайта';

    /** Адреса вне иерархии разделов — их обслуживают фиксированные маршруты. */
    private array $fixedPaths = ['/', '/search', '/glossary', '/fesoterika', '/forum'];

    private array $pageByUrl = [];   // url → Page
    private array $pagesBySlug = []; // slug → list<Page>
    private array $sectionUrls = []; // /wiki, /wiki/proekty…
    private array $redirectMap = []; // from_path → to_url

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $this->buildMaps();

        $redirects = Redirect::orderBy('from_path')->get();

        $moved = [];      // цель переехала → чиним
        $chains = [];     // цепочка → схлопываем
        $loops = [];      // A → B → A
        $missing = [];    // цели нет вообще
        $drafts = [];     // цель — черновик (404 для гостя)
        $shadowing = [];  // from_path перехватывает живую страницу
        $ok = 0;

        foreach ($redirects as $r) {
            // from_path, совпадающий с адресом живой страницы, делает её
            // недоступной: middleware отрабатывает ДО маршрутизации.
            if (isset($this->pageByUrl[$r->from_path]) || in_array($r->from_path, $this->sectionUrls, true)) {
                $shadowing[] = $r;
            }

            if (! str_starts_with($r->to_url, '/')) {
                $ok++;

                continue; // внешняя цель (/go/* → Дзен и т.п.)
            }

            [$final, $hops, $looped] = $this->follow($r->to_url);

            if ($looped) {
                $loops[] = [$r, $final];

                continue;
            }

            if ($hops > 0) {
                $chains[] = [$r, $final];

                continue;
            }

            $base = strtok($final, '?');
            if ($this->exists($base)) {
                if ($this->isDraft($base)) {
                    $drafts[] = [$r, $final];
                } else {
                    $ok++;
                }

                continue;
            }

            // Цель не резолвится: возможно, страница просто переехала в другой
            // корневой раздел — тогда slug тот же, а адрес другой.
            $actual = $this->findBySlug($base);
            if ($actual !== null) {
                $moved[] = [$r, $actual];
            } else {
                $missing[] = $r;
            }
        }

        $this->summary($redirects->count(), $ok, $moved, $chains, $loops, $missing, $drafts, $shadowing);

        if (! $fix) {
            if ($moved !== [] || $chains !== []) {
                $this->newLine();
                $this->comment('Исправить автоматически: --fix');
            }

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($moved as [$r, $actual]) {
            $r->update(['to_url' => $actual]);
            $this->line("  ✔ {$r->from_path}: цель → {$actual}");
            $fixed++;
        }
        foreach ($chains as [$r, $final]) {
            $r->update(['to_url' => $final]);
            $this->line("  ✔ {$r->from_path}: цепочка схлопнута → {$final}");
            $fixed++;
        }

        $this->newLine();
        $this->info("Исправлено записей: {$fixed}.");

        return self::SUCCESS;
    }

    private function buildMaps(): void
    {
        foreach (Page::with('section.parent')->get() as $p) {
            $this->pageByUrl[$p->url()] = $p;
            $this->pagesBySlug[$p->slug][] = $p;
        }
        foreach (Section::with('parent')->get() as $s) {
            $this->sectionUrls[] = $s->url();
        }
        foreach (Redirect::all() as $r) {
            $this->redirectMap[$r->from_path] = $r->to_url;
        }
    }

    /**
     * Проходит цепочку редиректов до конечного адреса.
     *
     * @return array{0:string,1:int,2:bool} [конечный адрес, число хопов, петля?]
     */
    private function follow(string $url): array
    {
        $seen = [$url => true];
        $hops = 0;

        while (isset($this->redirectMap[$url])) {
            $url = $this->redirectMap[$url];
            $hops++;
            if (isset($seen[$url]) || $hops > 10) {
                return [$url, $hops, true];
            }
            $seen[$url] = true;
        }

        return [$url, $hops, false];
    }

    private function exists(string $path): bool
    {
        return isset($this->pageByUrl[$path])
            || in_array($path, $this->sectionUrls, true)
            || in_array($path, $this->fixedPaths, true)
            || str_starts_with($path, '/forum/')
            || str_starts_with($path, '/storage/');
    }

    private function isDraft(string $path): bool
    {
        return isset($this->pageByUrl[$path]) && ! $this->pageByUrl[$path]->isPublished();
    }

    /** Страница с таким slug, но по другому адресу (переехала между разделами). */
    private function findBySlug(string $path): ?string
    {
        $slug = basename($path);
        $candidates = $this->pagesBySlug[$slug] ?? [];

        return count($candidates) === 1 ? $candidates[0]->url() : null;
    }

    private function summary(int $total, int $ok, array $moved, array $chains, array $loops, array $missing, array $drafts, array $shadowing): void
    {
        $this->info("Редиректов всего: {$total}. Рабочих: {$ok}.");

        $this->section('Цель переехала — чинится --fix', $moved, fn ($i) => "{$i[0]->from_path} → {$i[0]->to_url}  ⇒  {$i[1]}");
        $this->section('Цепочка (лишний хоп) — чинится --fix', $chains, fn ($i) => "{$i[0]->from_path} → {$i[0]->to_url}  ⇒  {$i[1]}");
        $this->section('ПЕТЛЯ — нужна ручная правка', $loops, fn ($i) => "{$i[0]->from_path} → {$i[0]->to_url}");
        $this->section('from_path перехватывает живую страницу — ручная правка', $shadowing, fn ($r) => "{$r->from_path} → {$r->to_url}");
        $this->section('Цели нет — нужен человек (страница не импортирована?)', $missing, fn ($r) => "{$r->from_path} → {$r->to_url}");
        $this->section('Цель — черновик (404 у гостя, пройдёт после публикации)', $drafts, fn ($i) => "{$i[0]->from_path} → {$i[0]->to_url}", 10);
    }

    private function section(string $caption, array $items, callable $format, int $limit = 40): void
    {
        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->comment($caption.': '.count($items));
        foreach (array_slice($items, 0, $limit) as $item) {
            $this->line('  '.$format($item));
        }
        if (count($items) > $limit) {
            $this->line('  … ещё '.(count($items) - $limit));
        }
    }
}
