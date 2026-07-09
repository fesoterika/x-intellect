{{-- Список карточек раздела + кнопка «Показать ещё».
     Используется и в полной странице раздела, и как partial-ответ
     (?partial=1) для дозагрузки следующих страниц через Alpine. --}}
<div id="section-items" class="section-grid {{ ($variant ?? null) === 'wiki' ? 'section-grid--wiki' : '' }}">
    @forelse ($pages as $page)
        @include('site.partials.page-card', ['page' => $page])
    @empty
        <p style="color: var(--xi-ink-faint);">Материалы раздела готовятся к публикации из архива.</p>
    @endforelse
</div>

@if ($pages->hasMorePages())
    <div class="load-more-wrap">
        {{-- Настоящая ссылка на ?page=N: без JS откроет следующую страницу
             целиком (SEO/доступность), с JS - перехват и дозагрузка. --}}
        <a class="load-more" href="{{ $pages->nextPageUrl() }}" rel="next" @click.prevent="next($el)">
            <span x-show="!loading">Показать ещё</span>
            <span x-show="loading" x-cloak>Загрузка…</span>
        </a>
    </div>
@endif
