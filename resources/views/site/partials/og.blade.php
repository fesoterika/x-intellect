{{--
    Open Graph для страниц, у которых нет seo-полей в базе (разделы, глоссарий,
    форум). У материалов эти теги ставит site.partials.seo из полей модели.

    Параметры: ogTitle (обязателен), ogDescription, ogUrl, ogImage, ogType.
    Без ogImage подставляется логотип — превью в мессенджерах и соцсетях
    не должно оставаться пустым.
--}}
@php
    $ogType = $ogType ?? 'website';
    $ogUrl = $ogUrl ?? rtrim(config('app.url'), '/').request()->getPathInfo();
    $ogImage = $ogImage ?? asset('images/x-intellect_logo.webp');
@endphp
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $ogTitle }}">
@isset($ogDescription)
    <meta property="og:description" content="{{ $ogDescription }}">
@endisset
<meta property="og:url" content="{{ $ogUrl }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:site_name" content="X-Intellect">
<meta property="og:locale" content="ru_RU">
