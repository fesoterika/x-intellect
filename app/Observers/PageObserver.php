<?php

namespace App\Observers;

use App\Jobs\RegenerateSitemap;
use App\Models\Page;
use App\Services\GlossaryLinker;
use App\Services\ImageAligner;
use App\Services\ImageSeo;
use App\Services\SeoService;
use App\Services\TableImagePairer;
use App\Services\TimelineTagger;
use App\Services\TrixTables;

class PageObserver
{
    public function __construct(
        protected SeoService $seo,
        protected GlossaryLinker $glossary,
        protected ImageSeo $imageSeo,
        protected TimelineTagger $timeline,
        protected ImageAligner $imageAligner,
        protected TrixTables $tables,
        protected TableImagePairer $pairer,
    ) {}

    public function saving(Page $page): void
    {
        // Таблицы-вложения Trix → чистый <table> (см. TrixTables) — ДО
        // остальной обработки, чтобы alt картинок и тултипы глоссария
        // увидели содержимое таблиц
        $page->body = $this->tables->extract($page->body);
        // Проставляем alt изображениям до генерации slug/описания и рендера
        $page->body = $this->imageSeo->process($page->body, $page->title);
        // Восстанавливаем класс таймлайна (Trix его вырезает)
        $page->body = $this->timeline->process($page->body);

        $this->seo->ensureSlug($page);
        $this->seo->fillDefaults($page);

        if ($page->isDirty('body') || $page->body_rendered === null) {
            // выравнивание картинок (класс на фигуру по alignment из Trix) →
            // пары «картинка + таблица» в одну линию → тултипы глоссария
            $page->body_rendered = $this->glossary->process(
                $this->pairer->process($this->imageAligner->process($page->body)),
            );
        }

        if ($page->isPublished() && $page->published_at === null) {
            $page->published_at = now();
        }
    }

    /**
     * Версии страницы: перед изменением заголовка/тела прежняя редакция
     * сохраняется в page_revisions — вкладка «История изменений».
     */
    public function updating(Page $page): void
    {
        if ($page->isDirty(['title', 'body'])) {
            $page->revisions()->create([
                'title' => $page->getOriginal('title'),
                'body' => $page->getOriginal('body'),
                'source_type' => $page->getOriginal('source_type'),
                'source_url' => $page->getOriginal('source_url'),
                'archived_at' => $page->getOriginal('archived_at'),
                'note' => 'Автосохранение перед правкой '.now()->format('d.m.Y H:i'),
            ]);
        }
    }

    public function saved(Page $page): void
    {
        if ($page->isPublished() || $page->wasChanged('status')) {
            RegenerateSitemap::dispatch();
        }
    }

    public function deleted(Page $page): void
    {
        RegenerateSitemap::dispatch();
    }
}
