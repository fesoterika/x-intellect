{{-- «Архив в цифрах» — счётчики опубликованного (см. HomeController::$stats).
     Числа отрендерены сервером (SEO, без JS), JS в app.js анимирует отсчёт
     от нуля при появлении блока во вьюпорте. --}}
@php
    use App\Support\RussianText;

    $items = [
        ['value' => $stats['sections'], 'label' => RussianText::plural($stats['sections'], 'раздел', 'раздела', 'разделов')],
        ['value' => $stats['pages'], 'label' => RussianText::plural($stats['pages'], 'страница', 'страницы', 'страниц')],
        ['value' => $stats['audio'], 'label' => RussianText::plural($stats['audio'], 'аудиозапись', 'аудиозаписи', 'аудиозаписей')],
        ['value' => $stats['terms'], 'label' => RussianText::plural($stats['terms'], 'термин', 'термина', 'терминов')],
    ];
@endphp

<section class="home-stats">
    <div class="home-section-head">
        <h2 class="section-title" style="margin: 0;">Архив в цифрах</h2>
        <span class="home-section-hint">опубликовано и доступно читателям</span>
    </div>

    <div class="stats-strip">
        @foreach ($items as $item)
            <div class="stat-cell">
                <span class="stat-value" data-count="{{ $item['value'] }}">{{ number_format($item['value'], 0, ',', ' ') }}</span>
                <span class="stat-label">{{ $item['label'] }}</span>
            </div>
        @endforeach
    </div>
</section>
