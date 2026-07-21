{{-- Кнопка «наверх» в правом нижнем углу. Подключается точечно на длинных
     страницах (статьи, вики, глоссарий, форум) - на главной и в разделах не нужна.

     Показ через CSS-класс, а не x-show/x-transition: базовое состояние в CSS -
     visibility:hidden, поэтому без JS кнопки просто нет (и она не в порядке
     обхода Tab), а появление анимируется обычным CSS-переходом. --}}
<button type="button"
        class="xi-to-top"
        :class="{ 'is-shown': shown }"
        x-data="{ shown: false, threshold: 600 }"
        x-init="shown = window.scrollY > threshold"
        @scroll.window.passive="shown = window.scrollY > threshold"
        @click="window.scrollTo({
            top: 0,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
        })"
        aria-label="Наверх"
        title="Наверх">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="12" y1="19" x2="12" y2="6"/><polyline points="5 13 12 6 19 13"/>
    </svg>
</button>
