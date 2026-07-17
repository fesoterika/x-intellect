{{-- Низ листинга раздела: слева страницы пагинации, справа выбор сортировки.
     На смартфоне колонки складываются — сортировка встаёт под пагинацией. --}}
@if ($pages->total() > 1)
    <div class="section-footer">
        <div class="section-footer-pages">
            {{ $pages->onEachSide(1)->links('site.partials.pagination') }}
        </div>

        {{-- GET-форма на адрес раздела: смена сортировки сбрасывает страницу.
             С JS селектор отправляет форму сам, без JS есть кнопка «Показать». --}}
        <form class="section-sort" method="GET" action="{{ url($section->url()) }}">
            <label for="section-sort-select">Сортировка:</label>
            {{-- Выбор запоминается в localStorage (как тема) и применяется на всех
                 листингах ранним скриптом в head — см. site/section.blade.php --}}
            <select id="section-sort-select" name="sort"
                    onchange="try { localStorage.setItem('xi-sort', this.value); } catch (e) {} this.form.submit()">
                @foreach (\App\Http\Controllers\Site\SectionController::SORTS as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="section-sort-apply">Показать</button></noscript>
        </form>
    </div>
@endif
