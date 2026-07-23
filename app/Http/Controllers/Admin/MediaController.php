<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Services\OrphanMedia;
use App\Support\RussianText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $media = Media::query()
            ->with('page')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            // Фильтр по привязанной странице — параметр page_id: имя page
            // занято пагинатором (?page=2 ломало бы и фильтр, и пагинацию)
            ->when($request->query('page_id'), fn ($q, $p) => $q->where('page_id', $p))
            ->when($request->query('q'), function ($q, $term) {
                // Регистронезависимо и с поддержкой кириллицы (см. RussianText).
                $q->where(function ($sub) use ($term) {
                    RussianText::contains($sub, 'title', $term);
                    RussianText::contains($sub, 'file_path', $term, 'or');
                });

                // Совпадения в названии — выше совпадений только по пути файла
                RussianText::containsFirstOrder($q, 'title', $term);
            })
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.media.index', [
            'media' => $media,
            'pages' => Page::orderBy('title')->get(['id', 'title']),
            // Кнопка «Найти бесхозные» перезагружает страницу с ?orphans=1 —
            // скан по требованию (LIKE по всем текстам сайта не для каждого
            // открытия раздела); найденное показывает модалка подтверждения
            'orphans' => $request->boolean('orphans') ? app(OrphanMedia::class)->find() : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Media::MIMETYPES))],
            'page_id' => ['nullable', 'exists:pages,id'],
            'position' => ['nullable', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:0'],
            // либо загрузка файла, либо внешний URL (S3-хранилище при нехватке диска).
            // Содержимое обязано соответствовать выбранному типу: без mimetypes сюда
            // проходил .html/.svg и получал исполняемый URL на /storage (XSS).
            'file' => [
                'required_without:external_url', 'nullable', 'file', 'max:512000',
                Media::mimetypesRule($request->input('type')),
            ],
            'external_url' => ['required_without:file', 'nullable', 'url', 'max:2048'],
        ], [
            'file.mimetypes' => 'Формат файла не подходит для выбранного типа медиа.',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $data['file_path'] = $file->store('media/'.$data['type'], 'public');
            $data['mime'] = $file->getMimeType();
            $data['size'] = $file->getSize();
            $data['disk'] = 'public';
        } else {
            $data['file_path'] = $data['external_url'];
            $data['disk'] = 'public';
        }

        unset($data['file'], $data['external_url']);
        $data['position'] = $data['position'] ?? 0;

        Media::create($data);

        return back()->with('status', 'Медиафайл добавлен.');
    }

    public function update(Request $request, Media $medium)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'page_id' => ['nullable', 'exists:pages,id'],
            'position' => ['nullable', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:0'],
        ]);

        $medium->update($data);

        return back()->with('status', 'Медиафайл обновлён.');
    }

    public function destroy(Media $medium)
    {
        if (! str_starts_with($medium->file_path, 'http')) {
            Storage::disk($medium->disk)->delete($medium->file_path);
        }

        $medium->delete();

        return back()->with('status', 'Медиафайл удалён.');
    }

    /**
     * Массовое удаление бесхозных файлов, подтверждённых в модалке.
     * Удаляются ТОЛЬКО присланные id и только если запись всё ещё
     * бесхозна (между показом списка и подтверждением файл могли
     * привязать к странице — такой молча пропускается).
     */
    public function destroyOrphans(Request $request, OrphanMedia $orphans)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        foreach (Media::whereIn('id', $data['ids'])->get() as $medium) {
            if (! $orphans->isOrphan($medium)) {
                continue;
            }

            if (! str_starts_with($medium->file_path, 'http')) {
                Storage::disk($medium->disk)->delete($medium->file_path);
            }

            $medium->delete();
            $deleted++;
        }

        return redirect()->route('admin.media.index')
            ->with('status', $deleted > 0
                ? 'Удалено бесхозных файлов: '.$deleted.'.'
                : 'Бесхозных файлов среди выбранных не осталось — ничего не удалено.');
    }
}
