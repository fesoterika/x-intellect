@props(['href', 'label' => 'Редактировать'])

{{-- Кнопка правки у заголовка: видна только авторизованному редактору/админу
     (User::isEditor() = admin|editor). Ведёт в редактор админки. --}}
@auth
    @if (auth()->user()->isEditor())
        <a href="{{ $href }}" class="edit-link" title="{{ $label }}" aria-label="{{ $label }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
        </a>
    @endif
@endauth
