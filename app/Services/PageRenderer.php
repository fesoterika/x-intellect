<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Page;

/**
 * Рендер тела страницы для публичной части: разворачивает short-коды
 * [[audio:ID]] в HTML-блок аудиоплеера (Этап 4 плана).
 */
class PageRenderer
{
    public function render(Page $page): string
    {
        $html = $page->body_rendered ?: (string) $page->body;

        return preg_replace_callback('/\[\[audio:(\d+)\]\]/', function ($m) {
            $media = Media::find($m[1]);

            if (! $media || $media->type !== 'audio') {
                return '';
            }

            return view('site.partials.audio-player', [
                'tracks' => collect([$media]),
                'playerId' => 'audio-'.$media->id,
                'page' => $page,
            ])->render();
        }, $html);
    }
}
