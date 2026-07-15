{{-- Список карточек раздела (страница пагинации). --}}
<div id="section-items" class="section-grid {{ ($variant ?? null) === 'wiki' ? 'section-grid--wiki' : '' }}">
    @forelse ($pages as $page)
        @include('site.partials.page-card', ['page' => $page])
    @empty
        <p style="color: var(--xi-ink-faint);">Материалы раздела готовятся к публикации из архива.</p>
    @endforelse
</div>
