@php
    // Плеер получает плейлист (одна или несколько записей подряд).
    // Файлы отдаются веб-сервером напрямую с поддержкой Range-запросов -
    // перемотка работает без дополнительной настройки (Этап 4 плана).
    $trackData = $tracks->map(fn ($m) => [
        'id' => $m->id,
        'title' => $m->title,
        'url' => $m->url(),
        'duration' => $m->durationLabel(),
    ])->values();

    // Обложка для системного плеера ОС (Media Session API — экран блокировки/
    // шторка на iOS и Android): og-картинка страницы, иначе первая картинка
    // в тексте, иначе стандартная OG-заглушка (Page::coverImageUrl()).
    $cover = $page->coverImageUrl();
@endphp

<div class="audio-player"
     x-data="audioPlayer({{ Js::from($trackData) }}, {{ Js::from($cover) }}, {{ Js::from($page->title) }})"
     id="{{ $playerId ?? 'player' }}">
    <audio x-ref="audio" preload="metadata"></audio>

    <div class="ap-controls">
        <button type="button" class="ap-btn" @click="toggle()" :aria-label="playing ? 'Пауза' : 'Воспроизвести'">
            <svg x-show="!playing" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <svg x-show="playing" x-cloak viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
        </button>

        <button type="button" class="ap-skip" @click="skip(-15)" aria-label="Назад 15 секунд">−15с</button>
        <button type="button" class="ap-skip" @click="skip(15)" aria-label="Вперёд 15 секунд">+15с</button>

        <div class="ap-bar" @click="seek($event)" role="slider" aria-label="Позиция воспроизведения"
             :aria-valuenow="Math.round(progress)" aria-valuemin="0" aria-valuemax="100">
            <div class="ap-bar-fill" :style="`width: ${progress}%`"></div>
        </div>

        <span class="ap-time" x-text="`${format(currentTime)} / ${format(duration)}`"></span>

        <select class="ap-rate" x-model.number="rate" @change="setRate(rate)" aria-label="Скорость воспроизведения">
            <template x-for="r in rates" :key="r">
                <option :value="r" x-text="r + '×'" :selected="r === rate"></option>
            </template>
        </select>
    </div>

    <template x-if="tracks.length > 1">
        <div class="ap-playlist">
            <template x-for="(track, index) in tracks" :key="track.id">
                <button type="button" class="ap-track" :class="{ active: index === current }" @click="select(index, true)">
                    <span x-text="`${index + 1}. ${track.title}`"></span>
                    <span class="dur" x-text="track.duration || ''"></span>
                </button>
            </template>
        </div>
    </template>

    <template x-if="tracks.length === 1">
        <div class="ap-playlist" style="border: 0; padding-top: 8px; margin-top: 4px;">
            <span style="font-size: 13.5px; color: var(--xi-ink-soft);" x-text="tracks[0].title"></span>
        </div>
    </template>

    {{-- Запасной проигрыватель для старых устройств. Собственный интерфейс выше
         целиком держится на Alpine — он же подставляет <audio> источник, поэтому
         без Alpine (Safari 9-10 без ES-модулей, выключенный JS, сбой загрузки
         бандла) плеер оказывался мёртвым: пустой <audio> без src и без controls.

         Блок скрыт по умолчанию и раскрывается классом xi-no-alpine на <html>,
         который снимает сторож в layouts/site.blade.php. На современных
         браузерах не отображается никогда и вёрстку не меняет. --}}
    @if ($trackData->isNotEmpty())
    {{-- Источник намеренно в data-src, а не в src: так современные браузеры,
         где этот блок скрыт, гарантированно не делают по нему ни одного
         запроса. Ставит src и вызывает load() сторож в layouts/site.blade.php,
         и только в режиме деградации — заодно он поднимает preload до
         metadata: на старых iOS элемент с preload="none" остаётся
         неинициализированным, нативные кнопки видны, а воспроизведение по
         нажатию не начинается. --}}
    <div class="ap-fallback">
        <audio controls preload="none" data-src="{{ $trackData[0]['url'] }}">
            Ваш браузер не умеет воспроизводить аудио —
            <a href="{{ $trackData[0]['url'] }}">скачайте запись файлом</a>.
        </audio>

        <ul class="ap-fallback-list">
            @foreach ($trackData as $index => $track)
                <li>
                    <a href="{{ $track['url'] }}">{{ $index + 1 }}. {{ $track['title'] }}</a>
                    @if ($track['duration'])
                        <span class="dur">{{ $track['duration'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
