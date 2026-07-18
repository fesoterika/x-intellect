<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Str;

/**
 * Модуль SEO-автозаполнения (Этап 2/5 плана): slug с транслитерацией
 * кириллицы, meta_description из текста, тип Schema.org — всё с
 * возможностью ручного переопределения в форме редактирования.
 */
class SeoService
{
    public function ensureSlug(Page $page): void
    {
        // Страница автора (/fesoterika) — slug фиксированный, не трогаем
        if ($page->page_type === 'author' && $page->slug) {
            return;
        }

        if (blank($page->slug)) {
            $page->slug = Str::slug($page->title) ?: 'page';
        }

        $base = $page->slug;
        $i = 2;

        while (Page::where('slug', $page->slug)
            ->when($page->exists, fn ($q) => $q->whereKeyNot($page->getKey()))
            ->exists()) {
            $page->slug = $base.'-'.$i++;
        }
    }

    public function fillDefaults(Page $page): void
    {
        $seo = $page->seo ?? [];

        $seo['meta_title'] ??= $page->title.' — X-Intellect';
        $seo['meta_description'] ??= $this->metaDescription($page);
        $seo['schema_type'] ??= $page->page_type === 'author' ? 'Person' : 'Article';
        // canonical не запекаем: сохранённый APP_URL протухает при смене
        // окружения (dev-база отдала бы localhost на проде). Шаблоны строят
        // его от текущего APP_URL на лету; в поле живёт только ручное значение.

        $page->seo = $seo;
    }

    protected function metaDescription(Page $page): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($page->excerpt ?: (string) $page->body)));

        return Str::limit($text, 158, '…');
    }
}
