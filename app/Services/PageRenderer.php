<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Page;

/**
 * Рендер тела страницы для публичной части: разворачивает short-коды
 * [[audio:ID]] в HTML-блок аудиоплеера (Этап 4 плана) и включает
 * ленивую загрузку картинок контента.
 */
class PageRenderer
{
    public function render(Page $page): string
    {
        $html = $page->body_rendered ?: (string) $page->body;

        // Картинки тела — ниже первого экрана: браузер качает их по мере
        // прокрутки, а не все сразу (архивные страницы бывают с галереями).
        // На выдаче, а не при сохранении — покрывает уже отрендеренные тела.
        $html = preg_replace(
            '/<img (?![^>]*\bloading=)/i',
            '<img loading="lazy" decoding="async" ',
            $html,
        );

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
