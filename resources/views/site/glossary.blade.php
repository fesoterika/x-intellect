@extends('layouts.site')

@section('title', 'Глоссарий — толкователь терминов проекта X-Intellect')

@section('meta')
    <meta name="description" content="Глоссарий X-Intellect: толкователь специфических терминов и понятий, посредством которых происходит диалог с Силами.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/glossarij">
    {{-- FAQPage JSON-LD для глоссария (Этап 5 плана) --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $terms->map(fn ($term) => [
            '@type' => 'Question',
            'name' => $term->term,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $term->definition],
        ])->values()->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        'Вики' => '/wiki',
        'Глоссарий' => null,
    ]])

    <h1 class="page-title">Глоссарий</h1>
    <p style="color: var(--xi-ink-soft); max-width: 740px; margin-bottom: 24px;">
        Толкователь специфических терминов и понятий, которые используются в материалах
        сайта и посредством которых происходит диалог с Силами, понимание информации,
        передаваемой ими для людей.
    </p>

    @php
        // Группировка по первой букве термина (термины уже отсортированы в контроллере)
        $groups = $terms->groupBy(fn ($t) => mb_strtoupper(mb_substr($t->term, 0, 1)));
        $letters = $groups->keys();
    @endphp

    @if ($terms->isEmpty())
        <p style="color: var(--xi-ink-faint);">Термины глоссария готовятся к публикации из архива вики.</p>
    @else
        <div x-data="glossaryFilter()">
            {{-- Поиск по глоссарию --}}
            <div class="glossary-search">
                <svg class="glossary-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search" x-model="q" placeholder="Поиск по глоссарию…" aria-label="Поиск по глоссарию">
                <button type="button" class="glossary-search-clear" x-show="q !== ''" x-cloak @click="q = ''" aria-label="Очистить">×</button>
            </div>

            {{-- Алфавитный указатель --}}
            <nav class="glossary-alphabet" aria-label="Алфавитный указатель">
                @foreach ($letters as $letter)
                    <a href="#g-{{ $letter }}"
                       data-letter-search="{{ mb_strtolower($groups[$letter]->pluck('term')->implode(' ').' '.$groups[$letter]->pluck('definition')->implode(' ')) }}"
                       x-show="letterVisible($el)">{{ $letter }}</a>
                @endforeach
            </nav>

            {{-- Группы терминов по буквам --}}
            <div class="glossary-groups">
                @foreach ($letters as $letter)
                    <section class="glossary-group" id="g-{{ $letter }}" x-show="groupVisible($el)">
                        <h2 class="glossary-letter" aria-hidden="true">{{ $letter }}</h2>
                        <div class="glossary-list">
                            @foreach ($groups[$letter] as $term)
                                <div class="xi-card glossary-item" id="{{ $term->slug }}"
                                     data-search="{{ mb_strtolower($term->term.' '.$term->definition) }}"
                                     x-show="cardVisible($el)">
                                    <h3 class="glossary-term-title">{{ $term->term }}</h3>
                                    <p class="glossary-def">{{ $term->definition }}</p>
                                    @if ($term->page && $term->page->isPublished())
                                        <a href="{{ url($term->page->url()) }}" class="glossary-more">Подробнее →</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <p class="glossary-empty" x-show="q !== '' && !anyVisible()" x-cloak>
                По запросу «<span x-text="q"></span>» ничего не найдено.
            </p>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    function glossaryFilter() {
        return {
            q: '',
            norm(s) { return (s || '').toLowerCase().trim(); },
            cardVisible(el) {
                const q = this.norm(this.q);
                return q === '' || (el.dataset.search || '').includes(q);
            },
            groupVisible(el) {
                return [...el.querySelectorAll('[data-search]')].some((c) => this.cardVisible(c));
            },
            letterVisible(el) {
                const q = this.norm(this.q);
                return q === '' || (el.dataset.letterSearch || '').includes(q);
            },
            anyVisible() {
                return [...this.$root.querySelectorAll('[data-search]')].some((c) => this.cardVisible(c));
            },
        };
    }
</script>
@endpush
