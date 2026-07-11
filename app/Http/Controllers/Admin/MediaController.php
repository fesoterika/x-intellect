<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
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
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.media.index', [
            'media' => $media,
            'pages' => Page::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['audio', 'pdf', 'image'])],
            'page_id' => ['nullable', 'exists:pages,id'],
            'position' => ['nullable', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:0'],
            // либо загрузка файла, либо внешний URL (S3-хранилище при нехватке диска)
            'file' => ['required_without:external_url', 'nullable', 'file', 'max:512000'],
            'external_url' => ['required_without:file', 'nullable', 'url', 'max:2048'],
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
}
