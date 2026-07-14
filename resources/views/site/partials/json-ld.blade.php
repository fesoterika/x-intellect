@php
    // JSON-LD по типу страницы (Этап 5 плана)
    $base = rtrim(config('app.url'), '/');
    $schemaType = $page->seoValue('schema_type', 'Article');

    $jsonLd = match ($schemaType) {
        'Person' => [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => 'Ф. (@fesoterika)',
            'alternateName' => 'Fesoterika',
            'description' => $page->seoValue('meta_description'),
            'url' => $base.$page->url(),
            // Внешние профили - помогают поисковикам и LLM-краулерам
            // связывать страницу с личностью автора
            'sameAs' => [
                'https://dzen.ru/fesoterika',
                'https://telegram.me/+Gd6NUYTFGG9iY2Q6',
                'https://vk.com/fesoterika',
                'https://github.com/Fesoterika',
            ],
        ],
        default => [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            'headline' => $page->title,
            'description' => $page->seoValue('meta_description'),
            'inLanguage' => 'ru',
            'datePublished' => $page->published_at?->toAtomString(),
            'dateModified' => $page->updated_at?->toAtomString(),
            'mainEntityOfPage' => $base.$page->url(),
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'X-Intellect', 'url' => $base.'/'],
        ] + ($page->source_url ? ['isBasedOn' => $page->source_url] : []),
    };
@endphp
<script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
