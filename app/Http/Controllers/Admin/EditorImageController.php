<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Загрузка изображений из редактора Trix: файл сразу уходит в хранилище
 * (диск public), а в тело материала подставляется ссылка на файл вместо
 * тяжёлого base64. Alt-атрибут проставляется на сохранении страницы
 * (см. App\Services\ImageSeo). Маршрут закрыт auth + role:admin,editor.
 */
class EditorImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:8192'],
        ]);

        $path = $request->file('file')->store('media/inline', 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
