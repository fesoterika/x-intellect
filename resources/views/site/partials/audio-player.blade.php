@php
    // Плеер получает плейлист (одна или несколько записей подряд).
    // Файлы отдаются веб-сервером напрямую с поддержкой Range-запросов —
    // перемотка работает без дополнительной настройки (Этап 4 плана).
    $trackData = $tracks->map(fn ($m) => [
        'id' => $m->id,
        'title' => $m->title,
        'url' => $m->url(),
        'duration' => $m->durationLabel(),
    ])->values();
@endphp

<div class="audio-player"
     x-data="audioPlayer({{ Js::from($trackData) }})"
     id="{{ $playerId ?? 'player' }}">
    <audio x-ref="audio" preload="metadata"></audio>

    <div class="ap-controls">
        <button type="button" class="ap-btn" @click="toggle()" :aria-label="playing ? 'Пауза' : 'Воспроизвести'">
            <svg x-show="!playing" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <svg x-show="playing" x-cloak viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
        </button>

        <button type="button" class="ap-skip" @click="skip(-15)" aria-label="Назад 15 секунд">−15с</button>
        <button type="button" class="ap-skip" @click="skip(30)" aria-label="Вперёд 30 секунд">+30с</button>

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
</div>
