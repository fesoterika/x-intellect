{{-- Динамические meta-теги из seo-полей страницы (Этап 5 плана) --}}
<meta name="description" content="{{ $page->seoValue('meta_description') }}">
<link rel="canonical" href="{{ $page->seoValue('canonical', rtrim(config('app.url'), '/').$page->url()) }}">

<meta property="og:type" content="article">
<meta property="og:title" content="{{ $page->seoValue('meta_title', $page->title) }}">
<meta property="og:description" content="{{ $page->seoValue('meta_description') }}">
<meta property="og:url" content="{{ $page->seoValue('canonical', rtrim(config('app.url'), '/').$page->url()) }}">
{{-- OG-картинка: значение поля, иначе — логотип сайта по умолчанию --}}
<meta property="og:image" content="{{ $page->seoValue('og_image') ?: asset('images/x-intellect_logo.webp') }}">

@include('site.partials.json-ld', ['page' => $page])
