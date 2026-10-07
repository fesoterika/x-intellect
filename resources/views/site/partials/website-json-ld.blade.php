@php
    $websiteUrl = rtrim(config('app.url'), '/').'/';
    $websiteJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $websiteUrl.'#website',
        'name' => 'X-Intellect',
        'url' => $websiteUrl,
        'inLanguage' => 'ru',
        'sameAs' => [
            'https://t.me/x_intellect',
            'https://vk.ru/xintellect',
            'https://vk.com/xintellect',
        ],
    ];
@endphp
<script type="application/ld+json">{!! json_encode($websiteJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
