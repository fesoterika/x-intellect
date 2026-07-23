<?php

namespace App\Services;

use App\Models\ForumPost;
use App\Models\GlossaryTerm;
use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Поиск «бесхозных» медиафайлов для массовой очистки в админке.
 *
 * Бесхозным считается файл, который (1) не привязан к странице (page_id)
 * И (2) не упоминается ни в одном содержимом сайта. Привязка page_id —
 * не единственный способ использования: аудио подключается short-кодом
 * [[audio:ID]] в тексте, а картинки/PDF из Trix-редактора живут в теле
 * страницы вложениями и получают page_id только если страница уже была
 * сохранена (см. EditorUploadController). Поэтому по всем текстовым
 * хранилищам — телам страниц, истории ревизий (восстановление ревизии не
 * должно ломать файлы), определениям глоссария и сообщениям форума —
 * ищется упоминание файла.
 *
 * Локальные файлы ищутся по ИМЕНИ файла (basename): имена в хранилище
 * случайные (store()/sha1 импортёров), а полный путь в теле может быть
 * закодирован по-разному (/storage/…, JSON Trix-вложения с \/). Внешние
 * URL (S3) ищутся целиком. Ложное срабатывание «упоминается» лишь
 * оставит файл — ошибиться можно только в безопасную сторону.
 */
class OrphanMedia
{
    /**
     * @return Collection<int, Media>
     */
    public function find(): Collection
    {
        return Media::whereNull('page_id')
            ->orderBy('type')
            ->orderBy('title')
            ->get()
            ->filter(fn (Media $media) => $this->isOrphan($media))
            ->values();
    }

    public function isOrphan(Media $media): bool
    {
        if ($media->page_id !== null) {
            return false;
        }

        // Аудио может играть на странице через short-код без привязки page_id
        if ($media->type === 'audio' && $this->isMentioned('[[audio:'.$media->id.']]')) {
            return false;
        }

        return ! $this->isMentioned($this->needle($media));
    }

    /**
     * Строка, по которой файл опознаётся в текстах (см. док-блок класса).
     */
    private function needle(Media $media): string
    {
        if (Str::startsWith($media->file_path, ['http://', 'https://'])) {
            return $media->file_path;
        }

        return basename($media->file_path);
    }

    private function isMentioned(string $needle): bool
    {
        // Экранируем спецсимволы LIKE — ищем буквальную подстроку
        $like = '%'.addcslashes($needle, '\\%_').'%';

        return Page::where('body', 'like', $like)->exists()
            || PageRevision::where('body', 'like', $like)->exists()
            || GlossaryTerm::where('definition', 'like', $like)->exists()
            || ForumPost::where('body', 'like', $like)->exists();
    }
}
