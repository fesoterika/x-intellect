// Админка: rich-text редактор Trix — клиентский JS-виджет, встраивается
// в Blade-форму без React/Vue-рантайма (Этап 2 плана)
import 'trix';
import 'trix/dist/trix.css';
// Стили контента редактора — ПОСЛЕ trix.css, чтобы перебить их и preflight
import '../css/trix-content.css';

// Метка content-вложения таблицы (синхронизирована с App\Services\TrixTables)
const TABLE_CONTENT_TYPE = 'application/vnd.xi-table+html';
// Метка content-вложения HTML-вставки (синхронизирована с App\Services\TrixEmbeds)
const EMBED_CONTENT_TYPE = 'application/vnd.xi-embed+html';

function isTableAttachment(att) {
    return att && att.getAttribute('contentType') === TABLE_CONTENT_TYPE;
}

function isEmbedAttachment(att) {
    return att && att.getAttribute('contentType') === EMBED_CONTENT_TYPE;
}

// SVG-иконки для кастомных кнопок панели (без текста)
const ICONS = {
    audio: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3l7-2v13.2A3.3 3.3 0 1 1 17 12V5.6l-3 .86V16.5A3.5 3.5 0 1 1 12 13z"/></svg>',
    image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.6" fill="currentColor" stroke="none"/><path d="M4 18l5-5 4 4 3-3 4 4"/></svg>',
    left: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4.5" width="8" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="13" y1="6" x2="21" y2="6"/><line x1="13" y1="10" x2="21" y2="10"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    center: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="5" x2="21" y2="5"/><rect x="7" y="8" width="10" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    right: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="13" y="4.5" width="8" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="6" x2="11" y2="6"/><line x1="3" y1="10" x2="11" y2="10"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    wide: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="9" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="17" x2="21" y2="17"/><line x1="3" y1="20.5" x2="21" y2="20.5"/></svg>',
    table: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
    code: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 8L4 12l4.5 4"/><path d="M15.5 8L20 12l-4.5 4"/><path d="M13.4 5.2l-2.8 13.6"/></svg>',
    pencil: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l4.3-1.1L19.6 7.6a2 2 0 0 0 0-2.8l-.4-.4a2 2 0 0 0-2.8 0L5.1 15.7z"/><path d="M13.5 6.5l4 4"/></svg>',
};

// Кнопки выравнивания картинки (иконка ICONS[key]; предпросмотр — на CSS по data-trix-attachment)
const ALIGN_BUTTONS = [
    { key: 'left', title: 'Обтекание текстом справа (картинка слева)' },
    { key: 'center', title: 'По центру' },
    { key: 'right', title: 'Обтекание текстом слева (картинка справа)' },
    { key: 'wide', title: 'Во всю ширину' },
];

// Карта выравнивания импортированных картинок (src → left/right/center/wide),
// отданная сервером в <script id="xi-align-map">. Нужна потому, что Trix при
// разборе <img class="xi-float-*"> превращает картинку в attachment и стирает
// класс — восстанавливаем выбор как атрибут Trix `alignment`.
let importedAlignMap = null;

function getImportedAlignMap() {
    if (importedAlignMap) return importedAlignMap;
    const el = document.getElementById('xi-align-map');
    try {
        importedAlignMap = el ? (JSON.parse(el.textContent) || {}) : {};
    } catch (e) {
        importedAlignMap = {};
    }
    return importedAlignMap;
}

function urlOfAttachment(att) {
    // getContent есть не у всех объектов вложений (в trix-attachment-add
    // приходит обёртка без него) — исключение здесь обрывает конвейер
    // рендера Trix, и правки перестают попадать в скрытое поле формы
    const content = typeof att.getContent === 'function' ? (att.getContent() || '') : '';

    return att.getAttribute('url') || content.match(/src="([^"]+)"/)?.[1] || '';
}

// Attachment под текущим выделением (клик по картинке выделяет её диапазоном).
function selectedAttachment(editorEl) {
    const editor = editorEl.editor;
    const range = editor.getSelectedRange();
    if (!range) return null;
    const doc = editor.getDocument();
    for (const att of doc.getAttachments()) {
        const r = doc.getRangeOfAttachment(att);
        if (r[1] > r[0] && range[0] <= r[0] && range[1] >= r[1]) return att;
    }
    return null;
}

// Предпросмотр выравнивания картинок и пар «картинка + таблица» в редакторе —
// ЧИСТЫЙ CSS по атрибуту data-trix-attachment фигур (атрибут содержит JSON
// с alignment и contentType, его пишет сам Trix при каждом рендере).
// Раньше классы вешал JS по событиям trix-render — с появлением таблиц-
// вложений это вошло в вечный цикл с внутренним MutationObserver Trix
// (класс стирался мгновенно): DOM внутри редактора мутировать нельзя.

// Уровни заголовков h2–h5 для контента материалов. H1 в тексте не нужен —
// это заголовок самой страницы; штатная кнопка Heading (h1) скрывается CSS.
document.addEventListener('trix-before-initialize', (event) => {
    [2, 3, 4, 5].forEach((level) => {
        window.Trix.config.blockAttributes[`heading${level}`] = {
            tagName: `h${level}`,
            terminal: true,
            breakOnReturn: true,
            group: false,
        };
    });
});

// Лимит и типы файлов редактора (синхронизированы с EditorUploadController)
const UPLOAD_MAX_MB = 170;
const UPLOAD_ERROR = `Не удалось загрузить файл. Допустимы изображения, аудио (mp3 и др.) и PDF до ${UPLOAD_MAX_MB} МБ.`;

function isUploadableType(file) {
    return /^(image|audio)\//.test(file.type) || file.type === 'application/pdf';
}

// Загрузка файла в хранилище (общая для drag-n-drop, скрепки и кнопки).
// Файл также регистрируется в разделе «Медиа»; pageId (если страница уже
// сохранена) привязывает его к странице. Возвращает промис с {id, url, type}.
function uploadFile(file, onProgress, pageId) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('file', file);
        if (pageId) form.append('page_id', pageId);

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/editor/upload', true);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf || '');
        xhr.setRequestHeader('Accept', 'application/json');

        // Реальный прогресс отдачи файла — двигает прогресс-бар на вложении
        if (onProgress) {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 90));
            });
        }

        xhr.addEventListener('load', () => {
            try {
                const json = JSON.parse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(json);
                    return;
                }
                // 422 и пр.: показываем причину от сервера (валидация)
                reject(new Error(json.message || 'upload failed'));
                return;
            } catch (e) { /* не-JSON — reject ниже */ }
            reject(new Error('upload failed'));
        });

        xhr.addEventListener('error', () => reject(new Error('network error')));
        xhr.send(form);
    });
}

// Ранний отсев неподдерживаемых/слишком больших файлов — до создания вложения
document.addEventListener('trix-file-accept', (event) => {
    if (!event.file) return;
    if (!isUploadableType(event.file) || event.file.size > UPLOAD_MAX_MB * 1024 * 1024) {
        event.preventDefault();
        alert(UPLOAD_ERROR);
    }
});

// ── Модальный редактор таблиц ────────────────────────────────────────────
// Таблица в теле — content-вложение Trix (непрозрачный блок, contentType
// TABLE_CONTENT_TYPE): внутри редактора её не поправить, поэтому правка идёт
// в модалке (ячейки contenteditable + операции со строками/столбцами),
// а результат кладётся обратно в attachment.setAttributes({content}).

// contenteditable="false" — иначе в таблицу можно печатать прямо в редакторе,
// а Trix такие правки не отслеживает и молча откатывает (правка — в модалке)
const EMPTY_TABLE_HTML = '<table contenteditable="false"><tr><td></td><td></td></tr><tr><td></td><td></td></tr></table>';

function openTableEditor(attachment) {
    if (document.querySelector('.xi-table-modal')) return; // одна модалка за раз

    const overlay = document.createElement('div');
    overlay.className = 'xi-table-modal';
    overlay.innerHTML = `
        <div class="xi-table-modal__dialog" role="dialog" aria-label="Редактор таблицы">
            <div class="xi-table-modal__bar">
                <strong>Таблица</strong>
                <span class="xi-table-modal__hint">кликните в ячейку и правьте текст</span>
            </div>
            <div class="xi-table-modal__body"></div>
            <div class="xi-table-modal__bar xi-table-modal__actions">
                <button type="button" data-act="row+">+ строка</button>
                <button type="button" data-act="row-">&minus; строка</button>
                <button type="button" data-act="col+">+ столбец</button>
                <button type="button" data-act="col-">&minus; столбец</button>
                <span class="xi-table-modal__spacer"></span>
                <button type="button" data-act="cancel">Отмена</button>
                <button type="button" data-act="save" class="xi-table-modal__save">Сохранить</button>
            </div>
        </div>`;

    const holder = overlay.querySelector('.xi-table-modal__body');
    holder.innerHTML = attachment.getAttribute('content') || EMPTY_TABLE_HTML;
    let table = holder.querySelector('table');
    if (!table) {
        holder.innerHTML = EMPTY_TABLE_HTML;
        table = holder.querySelector('table');
    }

    const editable = () => table.querySelectorAll('td, th').forEach((c) => c.setAttribute('contenteditable', 'true'));
    editable();

    const close = () => overlay.remove();

    // Защита от тихой потери правок: закрытие мимо «Сохранить» (подложка,
    // «Отмена», Esc) при несохранённых изменениях — только через подтверждение
    let dirty = false;
    holder.addEventListener('input', () => { dirty = true; });
    const closeDiscarding = () => {
        if (!dirty || confirm('Закрыть редактор таблицы БЕЗ сохранения изменений?')) close();
    };
    overlay.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closeDiscarding();
        }
    });

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeDiscarding(); // клик по подложке
            return;
        }

        const act = e.target.closest('[data-act]')?.dataset.act;
        if (!act) return;

        const rows = Array.from(table.rows);
        const last = rows[rows.length - 1];

        if (act === 'row+') {
            // новая строка повторяет структуру последней (число ячеек и теги)
            const tr = document.createElement('tr');
            Array.from(last?.cells ?? [null, null]).forEach((cell) => {
                tr.appendChild(document.createElement(cell?.tagName.toLowerCase() === 'th' ? 'th' : 'td'));
            });
            (last?.parentNode || table).appendChild(tr);
            dirty = true;
        } else if (act === 'row-' && rows.length > 1) {
            last.remove();
            dirty = true;
        } else if (act === 'col+') {
            rows.forEach((r) => r.appendChild(document.createElement(r.cells[0]?.tagName.toLowerCase() === 'th' ? 'th' : 'td')));
            dirty = true;
        } else if (act === 'col-' && (rows[0]?.cells.length ?? 0) > 1) {
            rows.forEach((r) => r.cells.length > 1 && r.deleteCell(-1));
            dirty = true;
        } else if (act === 'cancel') {
            closeDiscarding();
            return;
        } else if (act === 'save') {
            const clone = table.cloneNode(true);
            clone.querySelectorAll('[contenteditable]').forEach((c) => c.removeAttribute('contenteditable'));
            // запрет прямой правки в редакторе — только на самой таблице
            clone.setAttribute('contenteditable', 'false');
            attachment.setAttributes({ content: clone.outerHTML });
            close();
            return;
        }
        editable(); // новым ячейкам — contenteditable
    });

    document.body.appendChild(overlay);
    table.querySelector('td, th')?.focus();
}

// ── HTML-вставки со сторонних ресурсов ───────────────────────────────────
// Плейлисты, видео и прочее (в основном iframe): живьём в редакторе такую
// вставку не показать — содержимое вложения Trix проходит через его
// санитайзер, а тот вырезает iframe/script. Поэтому вставка — тоже
// content-вложение: в редакторе рисуется карточка с текстом кода, сам код
// лежит в атрибуте вложения `code` и разворачивается на сервере в
// <div class="xi-embed"> при сохранении (App\Services\TrixEmbeds).

const EMBED_PREVIEW_LIMIT = 400; // длина кода на карточке (как в TrixEmbeds::card)

// как htmlspecialchars(ENT_QUOTES) на сервере — карточки должны совпадать
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Близнец TrixEmbeds::card() на сервере — правки нужны в обоих местах
function embedCard(code) {
    const preview = code.length > EMBED_PREVIEW_LIMIT ? code.slice(0, EMBED_PREVIEW_LIMIT) + '…' : code;

    return '<div class="xi-embed-card">'
        + '<div class="xi-embed-card__label">HTML-вставка</div>'
        + '<pre class="xi-embed-card__code">' + escapeHtml(preview) + '</pre>'
        + '</div>';
}

/**
 * Диалог вставки кода на панели — по образцу штатного диалога ссылки, но без
 * какой-либо обработки введённого: что вставили, то и попадёт на страницу.
 * Возвращает функцию открытия диалога для правки готовой вставки.
 */
function createEmbedDialog(editorEl, embedBtn) {
    const toolbar = editorEl.toolbarElement;
    const dialog = document.createElement('div');
    dialog.className = 'trix-dialog xi-embed-dialog';
    dialog.dataset.trixDialog = 'xi-embed'; // = data-trix-action кнопки: показ берёт на себя Trix
    dialog.innerHTML = `
        <textarea class="trix-input trix-input--dialog xi-embed-dialog__code" rows="6"
                  aria-label="HTML-код вставки"
                  placeholder="&lt;iframe src=&quot;https://…&quot; width=&quot;560&quot; height=&quot;315&quot;&gt;&lt;/iframe&gt;"></textarea>
        <div class="xi-embed-dialog__actions">
            <input type="button" class="trix-button trix-button--dialog" value="Вставить" data-x-embed-save data-trix-method="hideDialog">
            <input type="button" class="trix-button trix-button--dialog" value="Отмена" data-trix-method="hideDialog">
            <span class="xi-embed-dialog__hint">на страницу пропускаются только &lt;iframe&gt; — остальной код вырежется при сохранении</span>
        </div>`;
    toolbar.querySelector('[data-trix-dialogs]')?.appendChild(dialog);

    const input = dialog.querySelector('.xi-embed-dialog__code');
    const saveBtn = dialog.querySelector('[data-x-embed-save]');
    let editing = null; // правим готовую вставку, а не создаём новую

    // Без iframe от блока после санитизации ничего не останется (TrixEmbeds) —
    // не даём вставить пустоту, вместо того чтобы молча её потерять
    const validate = () => { saveBtn.disabled = !/<iframe[\s>]/i.test(input.value); };
    input.addEventListener('input', validate);

    const fill = () => {
        input.value = editing ? (editing.getAttribute('code') || '') : '';
        validate();
        input.focus();
    };

    // Клик по кнопке панели — всегда новая вставка (правка идёт через open)
    embedBtn.addEventListener('mousedown', () => { editing = null; });

    // Диалог показал Trix (кнопкой) — остаётся заполнить поле
    editorEl.addEventListener('trix-toolbar-dialog-show', (event) => {
        if (event.dialogName === 'xi-embed') fill();
    });

    dialog.querySelector('[data-x-embed-save]').addEventListener('click', () => {
        // диалог закроется в любом случае: data-trix-method="hideDialog"
        // отработает следом (и вернёт фокус с выделением в редактор)
        const code = input.value.trim();
        const target = editing;
        if (code === '') {
            return;
        }

        if (target) {
            target.setAttributes({ code, content: embedCard(code) });
            return;
        }

        // Вставка — только ПОСЛЕ закрытия диалога (оно идёт следом за этим
        // обработчиком). Пока диалог открыт, Trix держит выделение
        // «замороженным» служебным текстовым атрибутом frozen, и новое
        // вложение унесло бы его с собой в тело — <span style="background-
        // color: highlight;"> вокруг вставки. Закрытие снимает заморозку и
        // возвращает курсор на прежнее место.
        setTimeout(() => {
            const editor = editorEl.editor;
            if (!editor.getSelectedRange()) {
                const end = editor.getDocument().getLength();
                editor.setSelectedRange([end, end]);
            }
            editor.insertAttachment(new window.Trix.Attachment({
                content: embedCard(code),
                contentType: EMBED_CONTENT_TYPE,
                code,
            }));
        });
    });

    return (attachment) => {
        editing = attachment;
        // Показать диалог кликом по кнопке нельзя: её mousedown сбросит
        // editing. Открываем сами — «показан» для Trix означает ровно этот
        // атрибут, так что «Отмена»/«Вставить» закроют диалог как свой.
        toolbar.querySelectorAll('[data-trix-dialog][data-trix-active]').forEach((el) => {
            el.removeAttribute('data-trix-active');
            el.classList.remove('trix-active');
        });
        dialog.setAttribute('data-trix-active', '');
        dialog.classList.add('trix-active');
        fill();
    };
}

// Кастомные кнопки на панели редактора: заголовки, «♪ Аудио», «🖼 Картинка»,
// «HTML-код», «Таблица», выравнивание картинок. Вызывается на trix-initialize и
// подстраховочно после загрузки: событие может уйти до регистрации
// слушателя, а панель — наполниться позже события (гонка инициализации
// чанков сборки), поэтому при пустой панели повторяем через кадр.
function enhanceEditor(editorEl) {
    const toolbar = editorEl.toolbarElement;
    const group = toolbar?.querySelector('.trix-button-group--block-tools');

    if (!editorEl.editor || !group) {
        return false; // редактор ещё не готов — вызовут повторно
    }

    // кнопка аудио живёт в отдельной группе панели — ищем по всей панели,
    // иначе гард не срабатывает и повторный вызов дублирует кнопки
    if (toolbar.querySelector('[data-x-audio]')) {
        return true; // уже дообогащена
    }

    // Кнопки уровней заголовков H2–H5 — на место штатной кнопки Heading (h1).
    const heading1 = group.querySelector('[data-trix-attribute="heading1"]');
    [2, 3, 4, 5].forEach((level) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'trix-button';
        btn.dataset.trixAttribute = `heading${level}`;
        btn.textContent = `H${level}`;
        btn.title = `Заголовок H${level}`;
        group.insertBefore(btn, heading1 || group.firstChild);
    });
    heading1?.remove();

    const row = toolbar.querySelector('.trix-button-row');

    // Отдельная группа «медиа»: только иконки (аудио, картинка)
    const mediaGroup = document.createElement('span');
    mediaGroup.className = 'trix-button-group xi-media-group';

    // Кнопка вставки аудиоплеера через short-код [[audio:ID]]
    const audioBtn = document.createElement('button');
    audioBtn.type = 'button';
    audioBtn.className = 'trix-button xi-icon-btn';
    audioBtn.dataset.xAudio = '1';
    audioBtn.innerHTML = ICONS.audio;
    audioBtn.title = 'Вставить аудиоплеер — short-код [[audio:ID]]';
    // mousedown с preventDefault сохраняет выделение/фокус редактора, иначе
    // после prompt() позиция вставки теряется и short-код не добавляется
    audioBtn.addEventListener('mousedown', (e) => e.preventDefault());
    audioBtn.addEventListener('click', () => {
        const id = prompt('ID медиафайла (см. раздел «Медиа»):');
        if (!id || !/^\d+$/.test(id.trim())) return;
        const editor = editorEl.editor;
        editorEl.focus();
        if (!editor.getSelectedRange()) {
            const end = editor.getDocument().getLength();
            editor.setSelectedRange([end, end]);
        }
        editor.insertString(`[[audio:${id.trim()}]]`);
    });
    mediaGroup.appendChild(audioBtn);

    // Кнопка вставки изображения в позицию курсора
    const imgBtn = document.createElement('button');
    imgBtn.type = 'button';
    imgBtn.className = 'trix-button xi-icon-btn';
    imgBtn.dataset.xImage = '1';
    imgBtn.innerHTML = ICONS.image;
    imgBtn.title = 'Вставить изображение';
    imgBtn.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.style.display = 'none';
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            input.remove();
            if (!file) return;
            // Нативная вставка файла Trix → сработает trix-attachment-add с файлом,
            // где мы грузим на сервер с прогресс-баром (виден прямо на картинке)
            editorEl.editor.insertFile(file);
        });
        document.body.appendChild(input);
        input.click();
    });
    mediaGroup.appendChild(imgBtn);

    // Кнопка вставки HTML-кода со стороны (плейлисты, видео — обычно iframe).
    // Открывает диалог с полем для кода; data-trix-action + одноимённый
    // data-trix-dialog означают, что показ/скрытие берёт на себя Trix — он же
    // на время диалога держит позицию курсора (как у штатной кнопки ссылки).
    const embedBtn = document.createElement('button');
    embedBtn.type = 'button';
    embedBtn.className = 'trix-button xi-icon-btn';
    embedBtn.dataset.trixAction = 'xi-embed';
    embedBtn.dataset.xEmbed = '1';
    embedBtn.innerHTML = ICONS.code;
    embedBtn.title = 'Вставить HTML-код с другого сайта — плеер, видео, плейлист (обычно iframe)';
    mediaGroup.appendChild(embedBtn);
    const openEmbedDialog = createEmbedDialog(editorEl, embedBtn);

    // Кнопка вставки таблицы (content-вложение + сразу модальный редактор)
    const tableBtn = document.createElement('button');
    tableBtn.type = 'button';
    tableBtn.className = 'trix-button xi-icon-btn';
    tableBtn.dataset.xTable = '1';
    tableBtn.innerHTML = ICONS.table;
    tableBtn.title = 'Вставить таблицу';
    tableBtn.addEventListener('mousedown', (e) => e.preventDefault());
    tableBtn.addEventListener('click', () => {
        const editor = editorEl.editor;
        editorEl.focus();
        if (!editor.getSelectedRange()) {
            const end = editor.getDocument().getLength();
            editor.setSelectedRange([end, end]);
        }
        const att = new window.Trix.Attachment({
            content: EMPTY_TABLE_HTML,
            contentType: TABLE_CONTENT_TYPE,
        });
        editor.insertAttachment(att);
        openTableEditor(att);
    });
    mediaGroup.appendChild(tableBtn);
    row?.appendChild(mediaGroup);

    // Группа кнопок выравнивания картинки (активны, когда выделена картинка)
    const alignGroup = document.createElement('span');
    alignGroup.className = 'trix-button-group xi-align-group';
    alignGroup.dataset.xAlign = '1';
    const alignBtns = ALIGN_BUTTONS.map(({ key, title }) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'trix-button xi-icon-btn xi-align-btn';
        b.dataset.align = key;
        b.innerHTML = ICONS[key];
        b.title = title;
        b.disabled = true;
        b.addEventListener('mousedown', (e) => e.preventDefault());
        b.addEventListener('click', () => {
            const att = selectedAttachment(editorEl);
            if (!att) return;
            // повторный клик по активному выравниванию — снять
            const next = att.getAttribute('alignment') === key ? '' : key;
            // предпросмотр обновится сам: Trix перерендерит фигуру с новым
            // data-trix-attachment, CSS среагирует на атрибут
            att.setAttributes({ alignment: next });
            updateAlignButtons(editorEl, alignBtns);
        });
        alignGroup.appendChild(b);
        return b;
    });

    // Кнопка «править блок» — активна, когда выделена таблица или HTML-вставка
    const editBlockBtn = document.createElement('button');
    editBlockBtn.type = 'button';
    editBlockBtn.className = 'trix-button xi-icon-btn';
    editBlockBtn.dataset.xBlockEdit = '1';
    editBlockBtn.innerHTML = ICONS.pencil;
    editBlockBtn.title = 'Править таблицу или HTML-вставку (или двойной клик по блоку)';
    editBlockBtn.disabled = true;
    editBlockBtn.addEventListener('mousedown', (e) => e.preventDefault());
    editBlockBtn.addEventListener('click', () => editBlock(selectedAttachment(editorEl)));
    alignGroup.appendChild(editBlockBtn);
    row?.appendChild(alignGroup);

    function editBlock(att) {
        if (isTableAttachment(att)) {
            openTableEditor(att);
        } else if (isEmbedAttachment(att)) {
            openEmbedDialog(att);
        }
    }

    // Двойной клик по блоку в тексте — тоже открывает правку
    editorEl.addEventListener('dblclick', (e) => {
        const fig = e.target.closest('figure.attachment[data-trix-id]');
        if (!fig) return;
        editBlock(editorEl.editor.getDocument().getAttachmentById(Number(fig.getAttribute('data-trix-id'))));
    });

    // Активность/подсветка кнопок выравнивания при изменении выделения
    editorEl.addEventListener('trix-selection-change', () => updateAlignButtons(editorEl, alignBtns, editBlockBtn));

    enhanceLinkDialog(toolbar);

    // Восстановление выравнивания импортированных картинок: событие
    // trix-attachment-add для уже загруженного контента не гарантировано,
    // поэтому проходим по всем вложениям после инициализации. Предпросмотр
    // рисует CSS по data-trix-attachment — setAttributes его сам обновит.
    const map = getImportedAlignMap();
    editorEl.editor.getDocument().getAttachments().forEach((att) => {
        if (!att.getAttribute('alignment')) {
            const url = urlOfAttachment(att);
            if (url && map[url]) att.setAttributes({ alignment: map[url] });
        }
    });

    return true;
}

// Чекбокс «открыть в новом окне» в диалоге ссылки. Модель Trix хранит у
// ссылки только href, поэтому выбор кодируется суффиксом #_blank в адресе:
// сервер при рендере снимает маркер и ставит target="_blank" (LinkTargets),
// а при повторном открытии диалога галочка восстанавливается по маркеру.
const BLANK_MARKER = '#_blank';

function enhanceLinkDialog(toolbar) {
    const dialog = toolbar?.querySelector('[data-trix-dialog="href"]');
    const input = dialog?.querySelector('input[name="href"]');
    if (!dialog || !input || dialog.querySelector('[data-x-blank]')) {
        return;
    }

    // Штатное поле Trix — type=url: браузер требует протокол и не даёт вставить
    // относительную ссылку (/wiki/…). Меняем на text; абсолютные ссылки на
    // localhost сервер при сохранении сам превращает в относительные (LocalLinks).
    input.type = 'text';
    input.placeholder = 'https://… или /wiki/stranica';

    const label = document.createElement('label');
    label.className = 'xi-link-blank';
    label.innerHTML = '<input type="checkbox" data-x-blank> открывать в новом окне';
    const checkbox = label.querySelector('input');
    const fields = dialog.querySelector('.trix-dialog__link-fields');
    if (fields) {
        fields.after(label);
    } else {
        dialog.appendChild(label);
    }

    // маркер в поле не показываем: прячем в чекбокс при открытии диалога
    // (Trix фокусирует поле при показе — ловим focusin)
    input.addEventListener('focusin', () => {
        if (input.value.endsWith(BLANK_MARKER)) {
            checkbox.checked = true;
            input.value = input.value.slice(0, -BLANK_MARKER.length);
        }
    });

    // перед тем как Trix прочитает значение (кнопка Link или Enter) —
    // подставляем/снимаем маркер по чекбоксу
    const normalize = () => {
        const bare = input.value.endsWith(BLANK_MARKER)
            ? input.value.slice(0, -BLANK_MARKER.length)
            : input.value;
        input.value = checkbox.checked && bare.trim() !== '' ? bare + BLANK_MARKER : bare;
    };
    dialog.querySelector('[data-trix-method="setAttribute"]')?.addEventListener('mousedown', normalize, true);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') normalize();
    }, true);
}

// Дообогащение панели не привязано к событию trix-initialize: оно может
// уйти до регистрации слушателя или сильно позже (порядок инициализации
// чанков сборки различается между окружениями). Поэтому: быстрый путь —
// событие, страховка — опрос готовности редактора раз в 150 мс до 20 с.
document.addEventListener('trix-initialize', (event) => enhanceEditor(event.target));

function enhanceWhenReady(editorEl) {
    if (enhanceEditor(editorEl)) return;
    let waited = 0;
    const timer = setInterval(() => {
        waited += 150;
        if (enhanceEditor(editorEl) || waited >= 20000) {
            clearInterval(timer);
        }
    }, 150);
}

document.querySelectorAll('trix-editor').forEach(enhanceWhenReady);
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('trix-editor').forEach(enhanceWhenReady);
});

function updateAlignButtons(editorEl, alignBtns, editBlockBtn) {
    const att = selectedAttachment(editorEl);
    // выравнивание — для картинок и HTML-вставок; таблица и так во всю ширину
    const isTable = isTableAttachment(att);
    const cur = att ? (att.getAttribute('alignment') || '') : null;
    alignBtns.forEach((b) => {
        b.disabled = !att || isTable;
        b.classList.toggle('trix-active', att && !isTable && b.dataset.align === cur);
    });
    if (editBlockBtn) editBlockBtn.disabled = !isTable && !isEmbedAttachment(att);
}

// Обработка вложений: загрузка новых файлов + восстановление выравнивания
// импортированных картинок (у которых Trix стёр класс при разборе).
document.addEventListener('trix-attachment-add', (event) => {
    const attachment = event.attachment;

    // Таблицы и HTML-вставки: контент правится своим редактором,
    // выравнивание/загрузка не про них. Событие прилетает и при setAttributes
    // из этого редактора — просто выходим.
    if (isTableAttachment(attachment) || isEmbedAttachment(attachment)) {
        return;
    }

    if (attachment.file) {
        // Новый файл (кнопка «Картинка»/скрепка/вставка/drag-n-drop): грузим в
        // хранилище вместо base64; прогресс-бар Trix виден прямо на вложении
        const editorEl = event.target;
        attachment.setUploadProgress(5);
        uploadFile(attachment.file, (pct) => attachment.setUploadProgress(pct), editorEl.dataset?.pageId)
            .then((res) => {
                if (res.type === 'audio') {
                    // Аудио — не вложение Trix, а short-код: на публичной
                    // странице развернётся в аудиоплеер (см. PageRenderer)
                    attachment.remove();
                    editorEl.editor.insertString(`[[audio:${res.id}]]`);
                    return;
                }
                attachment.setUploadProgress(100);
                attachment.setAttributes({ url: res.url, href: res.url });
            })
            .catch((err) => {
                attachment.remove();
                const serverMsg = err && err.message && err.message !== 'upload failed' && err.message !== 'network error';
                alert(serverMsg ? `${UPLOAD_ERROR}\n\n${err.message}` : UPLOAD_ERROR);
            });
        return;
    }

    // Без файла = вставка кнопкой (url готов) или картинка из уже сохранённого
    // контента. Для импортированных <img class="xi-float-*"> восстанавливаем
    // выбор выравнивания по снимку исходного тела.
    if (!attachment.getAttribute('alignment')) {
        const map = getImportedAlignMap();
        const url = urlOfAttachment(attachment);
        if (url && map[url]) {
            attachment.setAttributes({ alignment: map[url] });
        }
    }
});
