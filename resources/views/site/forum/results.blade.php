{{-- Найденные темы форума: фрагмент для живых подсказок (?partial=1)
     и блок результатов полной страницы поиска. --}}
@if ($topics->isEmpty())
    <p class="forum-search-empty">По запросу «{{ $query }}» тем не найдено.</p>
@else
    <p class="forum-search-count">{{ trans_choice('{1} Найдена :count тема|[2,4] Найдены :count темы|[5,*] Найдено :count тем', $topics->count()) }}</p>
    <ul class="forum-search-results">
        @foreach ($topics as $topic)
            <li>
                <a class="forum-topic-link" href="{{ $topic->url() }}">{{ $topic->title }}</a>
                <span class="forum-topic-meta">
                    {{ $topic->forum_title }} ·
                    {{ trans_choice('{1} :count сообщение|[2,4] :count сообщения|[5,*] :count сообщений', $topic->posts_count) }}@if ($topic->started_at) · {{ $topic->started_at->format('d.m.Y') }}@endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
