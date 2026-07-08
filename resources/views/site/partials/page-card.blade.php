<a class="page-card" href="{{ url($page->url()) }}">
    <div class="meta">
        <x-source-badge :page="$page" />
        @if ($page->archived_at)
            <span>редакция {{ $page->archived_at->format('Y') }} г.</span>
        @endif
        @if ($page->audio->count())
            <span>♪ аудио: {{ $page->audio->count() }}</span>
        @endif
    </div>
    <h3 style="margin-top: 10px;">{{ $page->title }}</h3>
    @if ($page->excerpt)
        <p>{{ Str::limit($page->excerpt, 140) }}</p>
    @endif
</a>
