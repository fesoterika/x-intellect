@extends('layouts.site')

@section('title', $page->seoValue('meta_title', 'Ф. (@fesoterika) - хранитель архива и разработчик сайта X-Intellect'))

@section('meta')
    @include('site.partials.seo', ['page' => $page])
@endsection

@section('content')
    {{-- Персональная страница автора/хранителя проекта - отдельный шаблон
         вне общей иерархии разделов (Этап 1 плана) --}}
    <article style="max-width: 760px; margin: 0 auto;">
        @include('site.partials.breadcrumbs', ['crumbs' => [
            'Главная' => '/',
            'Об авторе' => null,
        ]])

        <div class="xi-card" style="padding: 36px 34px;">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                <img src="/images/f_color.webp" alt="Портрет автора - Ф. (@fesoterika)" width="76" height="76"
                     style="width: 76px; height: 76px; border-radius: 50%; object-fit: cover; border: 1px solid var(--xi-accent-deep); background: var(--xi-accent-soft);">
                <div>
                    <div class="title-with-edit" style="margin: 0;">
                        <h1 class="page-title" style="margin: 0;">{{ $page->title }}</h1>
                        <x-edit-link :href="route('admin.pages.edit', $page)" label="Редактировать страницу" />
                    </div>
                    <p style="margin: 4px 0 0; color: var(--xi-ink-faint);">Хранитель архива и разработчик сайта</p>
                </div>
            </div>

            <div class="xi-prose">
                {!! $body !!}
            </div>

            {{-- Блок ссылок с заглушки: внешние ресурсы идут через внутренние
                 обёртки /go/*.html - механизм обхода adblock (таблица redirects) --}}
            <div class="author-links">
                <a class="btn-solid" href="/go/dzen.html" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 169 169" fill="currentColor" aria-hidden="true"><path d="M148.369 82.7304C148.369 82.0906 147.849 81.5608 147.209 81.5308C124.246 80.661 110.271 77.732 100.494 67.955C90.6967 58.1581 87.7776 44.1724 86.9079 21.1596C86.8879 20.5198 86.358 20 85.7082 20H83.0291C82.3893 20 81.8594 20.5198 81.8295 21.1596C80.9597 44.1624 78.0406 58.1581 68.2437 67.955C58.4568 77.742 44.4911 80.661 21.5283 81.5308C20.8885 81.5508 20.3687 82.0806 20.3687 82.7304V85.4096C20.3687 86.0494 20.8885 86.5792 21.5283 86.6092C44.4911 87.4789 58.4667 90.408 68.2437 100.185C78.0206 109.962 80.9397 123.908 81.8195 146.83C81.8394 147.47 82.3693 147.99 83.0191 147.99H85.7082C86.348 147.99 86.8779 147.47 86.9079 146.83C87.7876 123.908 90.7067 109.962 100.484 100.185C110.271 90.398 124.236 87.4789 147.199 86.6092C147.839 86.5892 148.359 86.0594 148.359 85.4096V82.7304H148.369Z"/></svg>
                    Дзен
                </a>
                <a class="btn-solid" href="https://telegram.me/+Gd6NUYTFGG9iY2Q6" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.94 4.3 18.7 19.6c-.24 1.08-.88 1.34-1.78.83l-4.92-3.63-2.37 2.28c-.26.26-.48.48-.99.48l.35-5 9.1-8.22c.4-.35-.09-.55-.62-.2L4.21 13.1l-4.85-1.52c-1.05-.33-1.07-1.05.22-1.56L20.6 2.78c.88-.32 1.64.2 1.34 1.52z" transform="translate(1 0)"/></svg>
                    Телеграм
                </a>
                <a class="btn-solid" href="https://vk.com/fesoterika" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.8 16.9c-5.5 0-8.96-3.9-9.1-10.3h2.78c.1 4.74 2.26 6.76 3.9 7.18V6.6h2.66v3.96c1.6-.18 3.28-2.05 3.84-3.96h2.6c-.43 2.34-2.24 4.2-3.52 4.97 1.28.62 3.34 2.25 4.13 5.33h-2.86c-.62-1.96-2.16-3.48-4.2-3.69v3.69z"/></svg>
                    ВКонтакте
                </a>
                <a class="btn-solid" href="https://github.com/fesoterika" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.58 2 12.25c0 4.53 2.87 8.37 6.84 9.73.5.1.68-.22.68-.49v-1.7c-2.78.62-3.37-1.22-3.37-1.22-.46-1.18-1.11-1.5-1.11-1.5-.9-.63.07-.62.07-.62 1 .07 1.53 1.05 1.53 1.05.89 1.56 2.34 1.11 2.91.85.09-.66.35-1.11.63-1.36-2.22-.26-4.55-1.14-4.55-5.06 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.7 0 0 .84-.28 2.75 1.05a9.36 9.36 0 0 1 5 0c1.91-1.33 2.75-1.05 2.75-1.05.55 1.4.2 2.44.1 2.7.64.72 1.03 1.63 1.03 2.75 0 3.93-2.34 4.79-4.57 5.05.36.32.68.94.68 1.9v2.82c0 .27.18.6.69.49A10.26 10.26 0 0 0 22 12.25C22 6.58 17.52 2 12 2z"/></svg>
                    GitHub
                </a>
                <a class="btn-outline" href="/go/about.html" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/></svg>
                    Кто я?
                </a>
                <a class="btn-outline" href="/go/donate.html" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    Поддержать
                </a>
            </div>
        </div>
    </article>
@endsection
