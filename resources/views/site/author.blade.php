@extends('layouts.site')

@section('title', $page->seoValue('meta_title', 'Ф. (@fesoterika) — хранитель архива и разработчик сайта X-Intellect'))

@section('meta')
    @include('site.partials.seo', ['page' => $page])
@endsection

@section('content')
    {{-- Персональная страница автора/хранителя проекта — отдельный шаблон
         вне общей иерархии разделов (Этап 1 плана) --}}
    <article style="max-width: 760px; margin: 0 auto;">
        @include('site.partials.breadcrumbs', ['crumbs' => [
            'Главная' => '/',
            'Об авторе' => null,
        ]])

        <div class="xi-card" style="padding: 36px 34px;">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                <div style="width: 76px; height: 76px; border-radius: 50%; background: var(--xi-accent-soft); border: 1px solid var(--xi-accent-deep); display: grid; place-items: center; font-size: 34px; color: var(--xi-accent); font-weight: 700;" aria-hidden="true">Ф</div>
                <div>
                    <h1 class="page-title" style="margin: 0;">{{ $page->title }}</h1>
                    <p style="margin: 4px 0 0; color: var(--xi-ink-faint);">Хранитель архива и разработчик сайта</p>
                </div>
            </div>

            <div class="xi-prose">
                {!! $body !!}
            </div>

            {{-- Блок ссылок с заглушки: внешние ресурсы идут через внутренние
                 обёртки /go/*.html — механизм обхода adblock (таблица redirects) --}}
            <div class="author-links">
                <a class="btn-solid" href="/go/dzen.html" target="_blank" rel="noopener noreferrer">Дзен</a>
                <a class="btn-solid" href="https://t.me/+Gd6NUYTFGG9iY2Q6" target="_blank" rel="noopener noreferrer">Телеграм</a>
                <a class="btn-solid" href="https://vk.com/fesoterika" target="_blank" rel="noopener noreferrer">ВКонтакте</a>
                <a class="btn-outline" href="/go/about.html" target="_blank" rel="noopener noreferrer">Кто я?</a>
                <a class="btn-outline" href="/go/donate.html" target="_blank" rel="noopener noreferrer">♥ Поддержать автора</a>
            </div>
        </div>
    </article>
@endsection
