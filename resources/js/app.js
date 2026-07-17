import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Аудиоплеер для вики-страниц (Этап 4 плана): Alpine.js-компонент без
 * React/Vue-рантайма. Play/pause, перемотка, скорость воспроизведения,
 * плейлист (записи курса подряд), сохранение позиции в localStorage —
 * важно для многочасовых записей курсов А. Глаза.
 */
Alpine.data('audioPlayer', (tracks) => ({
    tracks,
    current: 0,
    playing: false,
    progress: 0,
    currentTime: 0,
    duration: 0,
    rate: 1,
    rates: [0.75, 1, 1.25, 1.5, 2],

    init() {
        const audio = this.$refs.audio;

        audio.addEventListener('timeupdate', () => {
            this.currentTime = audio.currentTime;
            this.progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;

            // Позиция прослушивания сохраняется раз в ~5 секунд
            if (Math.floor(audio.currentTime) % 5 === 0 && audio.currentTime > 0) {
                localStorage.setItem(this.posKey(), String(Math.floor(audio.currentTime)));
            }
        });

        audio.addEventListener('loadedmetadata', () => {
            this.duration = audio.duration;

            const saved = parseInt(localStorage.getItem(this.posKey()) || '0', 10);
            if (saved > 5 && saved < audio.duration - 10) {
                audio.currentTime = saved;
            }
        });

        audio.addEventListener('ended', () => {
            localStorage.removeItem(this.posKey());
            if (this.current < this.tracks.length - 1) {
                this.select(this.current + 1, true);
            } else {
                this.playing = false;
            }
        });

        // Флаг playing синхронизируется с реальными событиями <audio> —
        // иначе он расходится с фактом (отклонённый play(), смена src, ended)
        audio.addEventListener('play', () => { this.playing = true; });
        audio.addEventListener('pause', () => { this.playing = false; });

        if (this.tracks.length) {
            audio.src = this.tracks[0].url;
        }
    },

    posKey() {
        return `xi-audio-pos-${this.tracks[this.current]?.id}`;
    },

    toggle() {
        const audio = this.$refs.audio;
        // по фактическому состоянию <audio>, а не по флагу — флаг обновят события play/pause
        audio.paused ? audio.play() : audio.pause();
    },

    select(index, autoplay = false) {
        const audio = this.$refs.audio;
        // запоминаем ДО смены src: она ставит аудио на паузу и сбрасывает флаг
        const resume = autoplay || this.playing;
        this.current = index;
        audio.src = this.tracks[index].url;
        audio.playbackRate = this.rate;

        if (resume) {
            audio.play();
        }
    },

    seek(event) {
        const audio = this.$refs.audio;
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - rect.left) / rect.width;

        if (audio.duration) {
            audio.currentTime = ratio * audio.duration;
        }
    },

    skip(seconds) {
        const audio = this.$refs.audio;
        // duration до загрузки метаданных = NaN: «|| 0» превращал +15с
        // в прыжок на начало (Math.min(0, …)) — ограничиваем сверху
        // только когда длительность уже известна
        const max = isFinite(audio.duration) ? audio.duration : Infinity;
        audio.currentTime = Math.max(0, Math.min(max, audio.currentTime + seconds));
    },

    setRate(rate) {
        this.rate = rate;
        this.$refs.audio.playbackRate = rate;
    },

    format(seconds) {
        if (!isFinite(seconds)) return '0:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60).toString().padStart(2, '0');
        return h > 0 ? `${h}:${m.toString().padStart(2, '0')}:${s}` : `${m}:${s}`;
    },
}));

Alpine.start();

/**
 * Тултипы глоссария (Этап 3 плана): термины размечаются при сохранении
 * страницы (span.glossary-term с data-атрибутами), подсказка показывается
 * при наведении/фокусе без тяжёлого фреймворка.
 */
const tooltip = document.createElement('div');
tooltip.className = 'glossary-tooltip';
tooltip.setAttribute('role', 'tooltip');
tooltip.hidden = true;
document.body.appendChild(tooltip);

function showTooltip(target) {
    tooltip.innerHTML = `<strong>${target.dataset.glossaryTerm}</strong><br>${target.dataset.glossaryDefinition}`;
    tooltip.hidden = false;

    const rect = target.getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 8;
    let left = rect.left + window.scrollX;

    left = Math.min(left, window.scrollX + document.documentElement.clientWidth - tooltip.offsetWidth - 12);

    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${Math.max(12, left)}px`;
}

['mouseover', 'focusin'].forEach((type) => {
    document.addEventListener(type, (event) => {
        const term = event.target.closest?.('.glossary-term');
        if (term) showTooltip(term);
    });
});

['mouseout', 'focusout'].forEach((type) => {
    document.addEventListener(type, (event) => {
        if (event.target.closest?.('.glossary-term')) tooltip.hidden = true;
    });
});

/**
 * Счётчики «Архив в цифрах» на главной: число отсчитывается от нуля, когда
 * блок попадает во вьюпорт. Значение уже отрендерено сервером — без JS и при
 * prefers-reduced-motion остаётся статичным (SEO и доступность не страдают).
 */
const statValues = document.querySelectorAll('.home-stats .stat-value[data-count]');

if (statValues.length && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const formatStat = new Intl.NumberFormat('ru-RU');

    const animateStat = (el) => {
        const target = parseInt(el.dataset.count, 10) || 0;
        const started = performance.now();
        const duration = 1100;

        const tick = (now) => {
            const t = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic: быстро в начале, мягко у цели

            el.textContent = formatStat.format(Math.round(target * eased));

            if (t < 1) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    };

    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                statsObserver.unobserve(entry.target);
                animateStat(entry.target);
            }
        });
    }, { threshold: 0.5 });

    statValues.forEach((el) => statsObserver.observe(el));
}
