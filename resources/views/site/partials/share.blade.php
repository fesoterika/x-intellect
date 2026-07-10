@php
    // Приватный блок «Поделиться»: только прямые ссылки-шаринги соцсетей и
    // копирование адреса. Никаких сторонних SDK/пикселей — данные на нашей
    // стороне не собираются, переход к сети происходит только по клику.
    $u = rawurlencode($url);
    $t = rawurlencode($title);
    // MAX и Bastyon исключены: у них нет простого web-share URL (MAX — только
    // Bot API, у Bastyon web-share не документирован), рабочего решения нет.
    $links = [
        'ВКонтакте'    => "https://vk.com/share.php?url={$u}&title={$t}",
        'Одноклассники'=> "https://connect.ok.ru/offer?url={$u}&title={$t}",
        'Телеграм'     => "https://t.me/share/url?url={$u}&text={$t}",
        'LiveJournal'  => "https://www.livejournal.com/update.bml?subject={$t}&event={$u}",
    ];
@endphp

<div class="share" x-data="{
    copied: false,
    copy(text) {
        const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1500); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(() => this.fallback(text, done));
        } else {
            this.fallback(text, done);
        }
    },
    fallback(text, done) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {} finally { ta.remove(); }
    }
}">
    <span class="share-label">Поделиться:</span>

    <a class="share-btn" href="{{ $links['ВКонтакте'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="ВКонтакте" aria-label="Поделиться во ВКонтакте">
        <svg viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="ВКонтакте"><title>ВКонтакте</title><path d="M12.8 16.9c-5.5 0-8.96-3.9-9.1-10.3h2.78c.1 4.74 2.26 6.76 3.9 7.18V6.6h2.66v3.96c1.6-.18 3.28-2.05 3.84-3.96h2.6c-.43 2.34-2.24 4.2-3.52 4.97 1.28.62 3.34 2.25 4.13 5.33h-2.86c-.62-1.96-2.16-3.48-4.2-3.69v3.69z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['Одноклассники'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="Одноклассники" aria-label="Поделиться в Одноклассниках">
        <svg viewBox="0 0 24 24" role="img" aria-label="Одноклассники"><title>Одноклассники</title><path fill="currentColor" d="M12 6.4a1.7 1.7 0 1 0 0 3.4 1.7 1.7 0 0 0 0-3.4M12 4a4.1 4.1 0 1 1 0 8.2A4.1 4.1 0 0 1 12 4M8.6 13.1a1.2 1.2 0 0 1 1.6-.4c1.1.7 2.5.7 3.6 0a1.2 1.2 0 0 1 1.3 2c-.7.5-1.5.8-2.3.9l2 2a1.2 1.2 0 1 1-1.7 1.7L12 17.4l-2.1 2a1.2 1.2 0 1 1-1.7-1.7l2-2c-.8-.2-1.6-.5-2.3-1a1.2 1.2 0 0 1-.4-1.6z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['Телеграм'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="Телеграм" aria-label="Поделиться в Телеграме">
        <svg viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="Телеграм"><title>Телеграм</title><path d="M21.94 4.3 18.7 19.6c-.24 1.08-.88 1.34-1.78.83l-4.92-3.63-2.37 2.28c-.26.26-.48.48-.99.48l.35-5 9.1-8.22c.4-.35-.09-.55-.62-.2L4.21 13.1l-4.85-1.52c-1.05-.33-1.07-1.05.22-1.56L20.6 2.78c.88-.32 1.64.2 1.34 1.52z" transform="translate(1 0)"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['LiveJournal'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="LiveJournal" aria-label="Поделиться в LiveJournal">
        <svg viewBox="0 0 32 32" fill="currentColor" role="img" aria-label="LiveJournal"><title>LiveJournal</title><path fill-rule="evenodd" d="M11.123 3.27L7.813 0A15.025 15.025 0 0 0 0 7.763l3.316 3.272c-.804 1.82-1.28 3.8-1.28 5.91 0 8.23 6.747 14.9 15.07 14.9 8.32 0 14.894-6.36 14.894-14.9S25.427 2.047 17.106 2.047c-2.125 0-4.148.437-5.983 1.223zM15.57 23.134a15.035 15.035 0 0 1 7.807-7.762L11.126 3.275h-.006a15.037 15.037 0 0 0-7.81 7.763l12.26 12.096z"/></svg>
    </a>

    <button type="button" class="share-btn share-copy"
            title="Скопировать ссылку" aria-label="Скопировать ссылку"
            @click="copy(@js($url))">
        <svg x-show="!copied" viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="Скопировать ссылку"><title>Скопировать ссылку</title><path d="M15 1H5a2 2 0 0 0-2 2v13h2V3h10V1z"/><path d="M19 5H9a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H9V7h10v14z"/></svg>
        <svg x-show="copied" x-cloak viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.55 17.6 4 12.05l1.4-1.4 4.15 4.15L18.6 5.4 20 6.8z"/></svg>
    </button>

    <span class="share-copied" x-show="copied" x-cloak aria-live="polite">Ссылка скопирована</span>
</div>
