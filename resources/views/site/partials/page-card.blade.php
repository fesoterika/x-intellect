<a class="page-card epoch-{{ $page->source_type }}" href="{{ url($page->url()) }}">
    <div class="meta">
        <x-source-badge :page="$page" />
        @if ($page->archived_at)
            <span>из архива {{ $page->archived_at->format('Y') }} г.</span>
        @endif
        @if ($page->audio->count())
            <span class="meta-audio">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                аудио: {{ $page->audio->count() }}
            </span>
        @endif
    </div>
    <h3 style="margin-top: 10px;">{{ $page->title }}</h3>
    @if ($page->excerpt)
        <p>{{ Str::limit($page->excerpt, 140) }}</p>
    @endif
    <span class="page-card-more">Читать <span class="arr" aria-hidden="true">→</span></span>
</a>
