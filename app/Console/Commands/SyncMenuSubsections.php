<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Models\Section;
use Illuminate\Console\Command;

/**
 * Подразделы в выпадающих меню шапки. Идемпотентно, можно повторять на проде.
 *
 *   php artisan site:menu-subsections [--dry]
 *
 * Для каждого корневого пункта меню шапки, ведущего на корневой раздел
 * с видимыми подразделами, добавляются дочерние пункты-подразделы
 * (после уже существующих детей; дубли по URL не создаются). Подписи
 * и порядок добавленных пунктов админ дальше правит как обычно.
 */
class SyncMenuSubsections extends Command
{
    protected $signature = 'site:menu-subsections {--dry : Показать изменения без записи}';

    protected $description = 'Добавить подразделы в выпадающие меню шапки (идемпотентно)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $added = 0;

        $items = MenuItem::location('header')->root()->with('children')->get();

        foreach ($items as $item) {
            $slug = trim(parse_url($item->url, PHP_URL_PATH) ?? '', '/');
            if ($slug === '' || str_contains($slug, '/')) {
                continue; // пункт ведёт не на корневой раздел
            }

            $section = Section::root()->where('slug', $slug)->where('is_visible', true)->first();
            if (! $section) {
                continue;
            }

            $children = $section->children()->where('is_visible', true)->orderBy('position')->get();
            if ($children->isEmpty()) {
                continue;
            }

            $position = (int) $item->children->max('position');

            foreach ($children as $child) {
                if ($item->children->contains(fn ($m) => rtrim($m->url, '/') === rtrim($child->url(), '/'))) {
                    continue; // уже в меню
                }

                $this->line(sprintf('%s → %s  (%s)', $item->label, $child->title, $child->url()));
                $added++;

                if ($dry) {
                    continue;
                }

                MenuItem::create([
                    'label' => $child->title,
                    'url' => $child->url(),
                    'location' => 'header',
                    'parent_id' => $item->id,
                    'position' => ++$position,
                ]);
            }
        }

        $this->info("Готово. Добавлено пунктов подменю: {$added}.");

        return self::SUCCESS;
    }
}
