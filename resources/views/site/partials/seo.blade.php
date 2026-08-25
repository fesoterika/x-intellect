{{-- Динамические meta-теги из seo-полей страницы (Этап 5 плана) --}}
@php
    $seoUrl = $page->seoValue('canonical', rtrim(config('app.url'), '/').$page->url());
    $seoDesc = $page->seoValue('meta_description');
@endphp
<meta name="description" content="{{ $seoDesc }}">
<link rel="canonical" href="{{ $seoUrl }}">

@include('site.partials.og', [
    'ogType' => 'article',
    'ogTitle' => $page->seoValue('meta_title', $page->title),
    'ogDescription' => $seoDesc,
    'ogUrl' => $seoUrl,
    {{-- OG-картинка: значение поля, иначе — логотип сайта по умолчанию --}}
    'ogImage' => $page->seoValue('og_image') ?: asset('images/x-intellect_logo.webp'),
])

@include('site.partials.json-ld', ['page' => $page])
