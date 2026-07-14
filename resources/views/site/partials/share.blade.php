@php
    // Приватный блок «Поделиться»: только прямые ссылки-шаринги соцсетей и
    // копирование адреса. Никаких сторонних SDK/пикселей — данные на нашей
    // стороне не собираются, переход к сети происходит только по клику.
    $u = rawurlencode($url);
    $t = rawurlencode($title);
    // Bastyon исключён: web-share URL у него не документирован. MAX делится
    // через max.ru/:share (текст = заголовок + ссылка).
    $links = [
        'ВКонтакте'    => "https://vk.com/share.php?url={$u}&title={$t}",
        'Одноклассники'=> "https://connect.ok.ru/offer?url={$u}&title={$t}",
        'Телеграм'     => "https://telegram.me/share/url?url={$u}&text={$t}",
        'MAX'          => "https://max.ru/:share?text={$t}%20{$u}",
        'LiveJournal'  => "https://www.livejournal.com/update.bml?subject={$t}&event={$u}",
        'Мой Мир'      => "https://connect.mail.ru/share?url={$u}&title={$t}",
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
        <svg viewBox="2.7 5.6 18.39 12.3" fill="currentColor" role="img" aria-label="ВКонтакте"><title>ВКонтакте</title><path d="M12.8 16.9c-5.5 0-8.96-3.9-9.1-10.3h2.78c.1 4.74 2.26 6.76 3.9 7.18V6.6h2.66v3.96c1.6-.18 3.28-2.05 3.84-3.96h2.6c-.43 2.34-2.24 4.2-3.52 4.97 1.28.62 3.34 2.25 4.13 5.33h-2.86c-.62-1.96-2.16-3.48-4.2-3.69v3.69z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['Одноклассники'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="Одноклассники" aria-label="Поделиться в Одноклассниках">
        <svg viewBox="6.35 3 10.75 17.75" role="img" aria-label="Одноклассники"><title>Одноклассники</title><path fill="currentColor" d="M12 6.4a1.7 1.7 0 1 0 0 3.4 1.7 1.7 0 0 0 0-3.4M12 4a4.1 4.1 0 1 1 0 8.2A4.1 4.1 0 0 1 12 4M8.6 13.1a1.2 1.2 0 0 1 1.6-.4c1.1.7 2.5.7 3.6 0a1.2 1.2 0 0 1 1.3 2c-.7.5-1.5.8-2.3.9l2 2a1.2 1.2 0 1 1-1.7 1.7L12 17.4l-2.1 2a1.2 1.2 0 1 1-1.7-1.7l2-2c-.8-.2-1.6-.5-2.3-1a1.2 1.2 0 0 1-.4-1.6z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['Телеграм'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="Телеграм" aria-label="Поделиться в Телеграме">
        <svg viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="Телеграм"><title>Телеграм</title><path d="M21.94 4.3 18.7 19.6c-.24 1.08-.88 1.34-1.78.83l-4.92-3.63-2.37 2.28c-.26.26-.48.48-.99.48l.35-5 9.1-8.22c.4-.35-.09-.55-.62-.2L4.21 13.1l-4.85-1.52c-1.05-.33-1.07-1.05.22-1.56L20.6 2.78c.88-.32 1.64.2 1.34 1.52z" transform="translate(1 0)"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['MAX'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="MAX" aria-label="Поделиться в MAX">
        <svg viewBox="88 90 824 821" fill="currentColor" fill-rule="evenodd" role="img" aria-label="MAX"><title>MAX</title><path d="M508.211 878.328c-75.007 0-109.864-10.95-170.453-54.75-38.325 49.275-159.686 87.783-164.979 21.9 0-49.456-10.95-91.248-23.36-136.873-14.782-56.21-31.572-118.807-31.572-209.508 0-216.626 177.754-379.597 388.357-379.597 210.785 0 375.947 171.001 375.947 381.604.707 207.346-166.595 376.118-373.94 377.224m3.103-571.585c-102.564-5.292-182.499 65.7-200.201 177.024-14.6 92.162 11.315 204.398 33.397 210.238 10.585 2.555 37.23-18.98 53.837-35.587a189.8 189.8 0 0 0 92.71 33.032c106.273 5.112 197.08-75.794 204.215-181.95 4.154-106.382-77.67-196.486-183.958-202.574Z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['LiveJournal'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="LiveJournal" aria-label="Поделиться в LiveJournal">
        <svg viewBox="67 67 321 321" fill="currentColor" fill-rule="evenodd" role="img" aria-label="LiveJournal"><title>LiveJournal</title><path d="M268.769,306.818l50.575,10.875l-10.874-50.586l-10.602-44.864c0.043-0.022-0.011-0.086-0.011-0.086L178.027,102.34c-33.126,14.218-59.977,40.248-75.28,72.787l121.135,121.132L268.769,306.818z M292.327,229.465l7.897,34.674c-14.714,7.363-27.06,19.708-34.421,34.432l-34.674-7.896C243.601,263.488,265.14,241.95,292.327,229.465z M236.071,385c-81.348,0-147.288-65.94-147.288-147.277c0-22.392,5.027-43.594,13.965-62.596l-31.096-31.088C90.255,113.949,115.601,88.604,145.69,70l32.337,32.34c17.818-7.646,37.431-11.895,58.043-11.895c81.337,0,147.277,65.94,147.277,147.277S317.408,385,236.071,385z"/></svg>
    </a>

    <a class="share-btn" href="{{ $links['Мой Мир'] }}" target="_blank" rel="noopener noreferrer nofollow"
       title="Мой Мир" aria-label="Поделиться в Моём Мире (Mail.ru)">
        <svg viewBox="-8 -8 238.1 167" fill="currentColor" role="img" aria-label="Мой Мир"><title>Мой Мир</title><ellipse cx="67.9" cy="21.3" rx="21.3" ry="21.3"/><ellipse cx="154.4" cy="21.3" rx="21.3" ry="21.3"/><path d="M220.6 125.2L194.8 81c-3.2-5.4-10.1-7.3-15.6-4.1-5.4 3.2-7.3 10.1-4.1 15.5l3.8 6.4c-18.9 17.2-43 26.6-68.9 26.6-25.1 0-48.7-9-67.4-25.3l4.5-7.8c3.2-5.4 1.3-12.4-4.1-15.5-5.4-3.2-12.4-1.3-15.6 4.1L1.6 125.1c-3.2 5.4-1.3 12.4 4.1 15.5 1.8 1 3.8 1.5 5.7 1.5 3.9 0 7.7-2 9.8-5.6l8.2-14C52.2 141 80.3 151 110 151c30 0 59.1-10.7 82-29.7l9 15.3c2.1 3.6 5.9 5.6 9.8 5.6 1.9 0 3.9-.5 5.7-1.5 5.4-3.2 7.2-10.1 4.1-15.5z"/></svg>
    </a>

    <button type="button" class="share-btn share-copy"
            title="Скопировать ссылку" aria-label="Скопировать ссылку"
            @click="copy(@js($url))">
        <svg x-show="!copied" viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="Скопировать ссылку"><title>Скопировать ссылку</title><path d="M15 1H5a2 2 0 0 0-2 2v13h2V3h10V1z"/><path d="M19 5H9a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H9V7h10v14z"/></svg>
        <svg x-show="copied" x-cloak viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.55 17.6 4 12.05l1.4-1.4 4.15 4.15L18.6 5.4 20 6.8z"/></svg>
    </button>

    <span class="share-copied" x-show="copied" x-cloak aria-live="polite">Ссылка скопирована</span>
</div>
