<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Загрузка файлов из редактора Trix (кнопка «Картинка», скрепка, drag-n-drop):
 * файл уходит в хранилище (диск public) и регистрируется в разделе «Медиа»
 * (с привязкой к странице, если она уже сохранена). Ответ отдаёт тип:
 * картинка встаёт вложением в текст, аудио клиент заменяет на short-код
 * [[audio:ID]] (плеер на публичной странице), PDF — ссылкой-вложением.
 * URL корне-относительный (/storage/…), как в Media::url(): абсолютный из
 * APP_URL ломается при несовпадении хоста/порта. Лимит 170 МБ — под длинные
 * mp3 сеансов; серверные upload_max_filesize/post_max_size должны быть выше
 * (dev: launch.json, прод: public/.user.ini). Маршрут закрыт auth + role.
 */
class EditorUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:174080', // 170 МБ в килобайтах
                Media::mimetypesRule(), // общий белый список — см. Media::MIMETYPES
            ],
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $type = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'pdf',
        };

        $media = Media::create([
            'page_id' => $data['page_id'] ?? null,
            'type' => $type,
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Файл из редактора',
            // картинки — в media/inline (как раньше), аудио/pdf — в папки раздела «Медиа»
            'file_path' => $file->store($type === 'image' ? 'media/inline' : 'media/'.$type, 'public'),
            'disk' => 'public',
            'mime' => $mime,
            'size' => $file->getSize(),
        ]);

        return response()->json([
            'id' => $media->id,
            'url' => $media->url(),
            'type' => $type,
        ]);
    }
}
