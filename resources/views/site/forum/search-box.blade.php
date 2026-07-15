{{-- Поиск по форуму: визуально как поиск в глоссарии. С JS темы предлагаются
     по мере набора (живые подсказки под строкой); без JS форма ведёт на
     страницу результатов /forum/search. $live=false — без подсказок
     (на самой странице результатов, чтобы не дублировать выдачу). --}}
<div class="forum-search" @if ($live ?? true) x-data="forumSearch(@js(route('forum.search')))" @endif>
    <form class="glossary-search" action="{{ route('forum.search') }}" method="GET" role="search">
        <svg class="glossary-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="search" name="q" value="{{ $query ?? '' }}" placeholder="Поиск по форуму…"
               aria-label="Поиск по форуму" autocomplete="off"
               @if ($live ?? true) x-model="q" @input.debounce.300ms="suggest()" @endif>
        @if ($live ?? true)
            <button type="button" class="glossary-search-clear" x-show="q !== ''" x-cloak
                    @click="q = ''; html = ''" aria-label="Очистить">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>
                </svg>
            </button>
        @endif
    </form>

    @if ($live ?? true)
        <div class="forum-search-panel xi-card" x-show="html !== ''" x-cloak x-html="html" aria-live="polite"></div>
    @endif
</div>

@if ($live ?? true)
    @once
        @push('scripts')
        <script>
            /* Живые подсказки поиска по форуму: дозапрос найденных тем с ?partial=1.
               Предыдущий запрос прерывается — показывается только актуальный ввод. */
            function forumSearch(url) {
                return {
                    q: '',
                    html: '',
                    controller: null,

                    async suggest() {
                        const q = this.q.trim();
                        if (q.length < 2) {
                            this.html = '';
                            return;
                        }
                        this.controller?.abort();
                        this.controller = new AbortController();
                        try {
                            const res = await fetch(url + '?' + new URLSearchParams({ q, partial: 1 }), {
                                headers: { 'X-Requested-With': 'fetch' },
                                signal: this.controller.signal,
                            });
                            if (res.ok) this.html = await res.text();
                        } catch (e) {
                            /* прерван новым вводом или сеть недоступна — без шума */
                        }
                    },
                };
            }
        </script>
        @endpush
    @endonce
@endif
