<?php

namespace App\Observers;

use App\Jobs\RegenerateSitemap;
use App\Models\Page;
use App\Models\Redirect;
use App\Services\GlossaryLinker;
use App\Services\ImageAligner;
use App\Services\ImageFigures;
use App\Services\ImageGallery;
use App\Services\ImageSeo;
use App\Services\SeoService;
use App\Services\AttachmentDownloads;
use App\Services\LinkTargets;
use App\Services\LocalLinks;
use App\Services\TableImagePairer;
use App\Services\TimelineTagger;
use App\Services\TrixEmbeds;
use App\Services\TrixTables;
use Illuminate\Support\Str;

class PageObserver
{
    public function __construct(
        protected SeoService $seo,
        protected GlossaryLinker $glossary,
        protected ImageSeo $imageSeo,
        protected TimelineTagger $timeline,
        protected ImageAligner $imageAligner,
        protected ImageFigures $imageFigures,
        protected TrixTables $tables,
        protected TrixEmbeds $embeds,
        protected TableImagePairer $pairer,
        protected ImageGallery $gallery,
        protected LinkTargets $linkTargets,
        protected AttachmentDownloads $downloads,
        protected LocalLinks $localLinks,
    ) {}

    public function saving(Page $page): void
    {
        // Раздел сменился — подтянутая ранее связь указывает на прежний,
        // а из неё считается url() (и canonical ниже)
        if ($page->isDirty('section_id')) {
            $page->unsetRelation('section');
        }
        $this->refreshCanonicalOnMove($page);

        // Таблицы-вложения Trix → чистый <table> (см. TrixTables) — ДО
        // остальной обработки, чтобы alt картинок и тултипы глоссария
        // увидели содержимое таблиц
        $page->body = $this->tables->extract($page->body);
        // Вложения-вставки Trix → блок <div class="xi-embed"> с чужим кодом
        // (см. TrixEmbeds). Порядок обратный сборке в форме: там сначала
        // сворачиваются вставки, потом таблицы
        $page->body = $this->embeds->extract($page->body);
        // Вставленные в редакторе ссылки на localhost → относительные
        $page->body = $this->localLinks->relativize($page->body);
        // Проставляем alt изображениям до генерации slug/описания и рендера
        $page->body = $this->imageSeo->process($page->body, $page->title);
        // Восстанавливаем класс таймлайна (Trix его вырезает)
        $page->body = $this->timeline->process($page->body);

        $this->seo->ensureSlug($page);
        $this->seo->fillDefaults($page);

        if ($page->isDirty('body') || $page->body_rendered === null) {
            // маркеры «в новом окне» → target=_blank; выравнивание картинок
            // (класс на фигуру по alignment из Trix) → подписи и ссылки на
            // картинки → файлы-вложения кнопкой «Скачать» → пары «картинка +
            // таблица» → ряды миниатюр (строго после пар: обёртка галереи
            // разорвала бы соседство фигуры с таблицей) → тултипы глоссария
            $page->body_rendered = $this->glossary->process(
                $this->gallery->process(
                    $this->pairer->process(
                        $this->downloads->process(
                            $this->imageFigures->process(
                                $this->imageAligner->process($this->linkTargets->process($page->body)),
                            ),
                        ),
                    ),
                ),
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
            // «Отредактирована вручную» — только правки из админки; изменения
            // консольных команд (импорт/перелинковка) помечаются отдельно,
            // иначе refresh-защита импортёров принимает их за ручные правки
            $manual = ! app()->runningInConsole() || app()->runningUnitTests();

            $page->revisions()->create([
                'title' => $page->getOriginal('title'),
                'body' => $page->getOriginal('body'),
                'source_type' => $page->getOriginal('source_type'),
                'source_url' => $page->getOriginal('source_url'),
                'archived_at' => $page->getOriginal('archived_at'),
                'note' => ($manual ? 'Отредактирована вручную ' : 'Обновлена командой ').now()->format('d.m.Y H:i'),
            ]);
        }
    }

    public function saved(Page $page): void
    {
        $this->keepOldUrlAlive($page);

        if ($page->isPublished() || $page->wasChanged('status')) {
            RegenerateSitemap::dispatch();
        }
    }

    /**
     * Переезд страницы: старый адрес сохраняем 301, иначе смена раздела в
     * админке молча ломает ссылки и теряет накопленный вес.
     *
     * Адрес зависит от КОРНЕВОГО раздела (Page::url()), поэтому перенос между
     * подразделами одного корня адреса не меняет — редирект не нужен.
     */
    private function keepOldUrlAlive(Page $page): void
    {
        // На вставке wasChanged() пуст, так что создание сюда не попадает
        if (! $page->wasChanged(['section_id', 'slug'])) {
            return;
        }

        $oldUrl = $page->urlBeforeSave();
        $newUrl = $page->url();
        if ($oldUrl === null || $oldUrl === $newUrl) {
            return;
        }

        // Встречная запись (страницу вернули на прежний адрес) перехватила бы
        // новый адрес: middleware отрабатывает ДО маршрутизации, и страница
        // стала бы недоступна, а переходы зациклились.
        Redirect::where('from_path', $newUrl)->delete();

        Redirect::updateOrCreate(
            ['from_path' => $oldUrl],
            [
                'to_url' => $newUrl,
                'status_code' => 301,
                'comment' => 'Смена адреса: '.Str::limit($page->title, 50),
            ],
        );

        // Входящие редиректы ведём сразу на новый адрес: иначе накапливаются
        // цепочки «старый → прежний → новый», которые теряют вес на каждом хопе.
        Redirect::where('to_url', $oldUrl)
            ->where('from_path', '!=', $oldUrl)
            ->update(['to_url' => $newUrl]);
    }

    /**
     * Canonical, выставленный автоматически для прежнего адреса, обязан
     * переехать со страницей — иначе он спорит с собственным 301. Значение,
     * заданное руками, не трогаем.
     */
    private function refreshCanonicalOnMove(Page $page): void
    {
        if (! $page->exists || ! $page->isDirty(['section_id', 'slug'])) {
            return;
        }

        $oldUrl = $page->urlBeforeSave();
        $seo = $page->seo ?? [];
        if ($oldUrl === null || ! isset($seo['canonical'])) {
            return;
        }

        if ($seo['canonical'] === rtrim(config('app.url'), '/').$oldUrl) {
            $seo['canonical'] = null; // SeoService::fillDefaults подставит новый
            $page->seo = $seo;
        }
    }

    public function deleted(Page $page): void
    {
        RegenerateSitemap::dispatch();
    }
}
