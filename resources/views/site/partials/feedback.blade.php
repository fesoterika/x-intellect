@php
    // Плашка «Нашли ошибку?» под материалом: письмо уходит хранителю архива,
    // в теме — адрес страницы, с которой нажали на ссылку.
    // Адрес почты не пишем в HTML открытым текстом (спам-боты собирают mailto из
    // исходника): его склеивает JS в момент клика. rawurlencode даёт только
    // %XX/латиницу, поэтому строки безопасно вставлять в JS-литерал.
    $feedbackUrl = $url ?? url()->current();
    $mailQuery = '?subject='.rawurlencode('Ошибка на сайте: '.$feedbackUrl)
        .'&body='.rawurlencode('Подробно опишите найденную ошибку. Если нет стенографии или аудио и у вас, вдруг, что-то из перечисленного у вас есть - присылайте, вы поможете проекту! Если у вас нет и нет на сайте, значит этих данных не было найдено ни в одном архиве.');
@endphp

<div class="feedback">
    <p class="feedback-text">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 17h.01"/></svg>
        <span><strong>Нашли ошибку?</strong> Сообщите нам — поможете сделать архив точнее.</span>
    </p>
    <span class="feedback-actions">
        <a class="feedback-btn" href="#"
           x-data="{ addr: ['fezoterika', 'yandex.ru'] }"
           @click.prevent="window.location.href = 'mailto:' + addr.join('@') + '{{ $mailQuery }}'"
           title="Написать о найденной ошибке на почту" aria-label="Написать о найденной ошибке на почту">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg>
            Написать
        </a>
    </span>
</div>
