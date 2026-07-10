// Админка: rich-text редактор Trix — клиентский JS-виджет, встраивается
// в Blade-форму без React/Vue-рантайма (Этап 2 плана)
import 'trix';
import 'trix/dist/trix.css';
// Стили контента редактора — ПОСЛЕ trix.css, чтобы перебить их и preflight
import '../css/trix-content.css';

// SVG-иконки для кастомных кнопок панели (без текста)
const ICONS = {
    audio: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3l7-2v13.2A3.3 3.3 0 1 1 17 12V5.6l-3 .86V16.5A3.5 3.5 0 1 1 12 13z"/></svg>',
    image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.6" fill="currentColor" stroke="none"/><path d="M4 18l5-5 4 4 3-3 4 4"/></svg>',
    left: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4.5" width="8" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="13" y1="6" x2="21" y2="6"/><line x1="13" y1="10" x2="21" y2="10"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    center: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="5" x2="21" y2="5"/><rect x="7" y="8" width="10" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    right: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="13" y="4.5" width="8" height="7" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="6" x2="11" y2="6"/><line x1="3" y1="10" x2="11" y2="10"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="3" y1="18.5" x2="21" y2="18.5"/></svg>',
    wide: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="9" rx="1" fill="currentColor" stroke="none"/><line x1="3" y1="17" x2="21" y2="17"/><line x1="3" y1="20.5" x2="21" y2="20.5"/></svg>',
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
    return att.getAttribute('url') || (att.getContent() || '').match(/src="([^"]+)"/)?.[1] || '';
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

// Соответствие «выравнивание → CSS-класс фигуры» (совпадает с ImageAligner на сервере)
const ALIGN = { left: 'xi-float-left', right: 'xi-float-right', center: 'xi-align-center', wide: 'xi-align-wide' };
const ALIGN_CLASSES = Object.values(ALIGN);

// Живой предпросмотр: проставляем класс выравнивания на фигуры по значению из
// модели Trix. Меняем DOM только при отличии. Вызывается ПО СОБЫТИЯМ (клик,
// trix-render, trix-change) — НЕ из MutationObserver (тот конфликтует с
// внутренним наблюдателем Trix и вешает страницу).
function syncAlignments(editorEl) {
    const doc = editorEl.editor.getDocument();
    editorEl.querySelectorAll('figure.attachment[data-trix-id]').forEach((fig) => {
        const att = doc.getAttachmentById(Number(fig.getAttribute('data-trix-id')));
        const desired = (att && ALIGN[att.getAttribute('alignment')]) || '';
        const current = ALIGN_CLASSES.find((c) => fig.classList.contains(c)) || '';
        if (desired === current) return;
        if (current) fig.classList.remove(current);
        if (desired) fig.classList.add(desired);
    });
}

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

// Загрузка файла-картинки в хранилище (общая для drag-n-drop и кнопки).
// Возвращает промис с URL загруженного файла.
function uploadImage(file, onProgress) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('file', file);

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/editor/image', true);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf || '');
        xhr.setRequestHeader('Accept', 'application/json');

        // Реальный прогресс отдачи файла — двигает прогресс-бар на картинке
        if (onProgress) {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 90));
            });
        }

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    resolve(JSON.parse(xhr.responseText).url);
                    return;
                } catch (e) { /* провал парсинга — reject ниже */ }
            }
            reject(new Error('upload failed'));
        });

        xhr.addEventListener('error', () => reject(new Error('network error')));
        xhr.send(form);
    });
}

// Кастомные кнопки на панели редактора: заголовки, «♪ Аудио», «🖼 Картинка»,
// выравнивание картинок.
document.addEventListener('trix-initialize', (event) => {
    const editorEl = event.target;
    const toolbar = editorEl.toolbarElement;
    const group = toolbar?.querySelector('.trix-button-group--block-tools');

    if (!group || group.querySelector('[data-x-audio]')) {
        return;
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
            att.setAttributes({ alignment: next });
            syncAlignments(editorEl); // сразу отражаем в предпросмотре
            updateAlignButtons(editorEl, alignBtns);
        });
        alignGroup.appendChild(b);
        return b;
    });
    row?.appendChild(alignGroup);

    // Активность/подсветка кнопок выравнивания при изменении выделения
    editorEl.addEventListener('trix-selection-change', () => updateAlignButtons(editorEl, alignBtns));
    // Trix при ре-рендере фигуры стирает класс — переставляем на его событии
    // trix-render (не через MutationObserver — тот зацикливается)
    editorEl.addEventListener('trix-render', () => syncAlignments(editorEl));

    // Восстановление выравнивания импортированных картинок: событие
    // trix-attachment-add для уже загруженного контента не гарантировано,
    // поэтому проходим по всем вложениям после инициализации.
    const map = getImportedAlignMap();
    editorEl.editor.getDocument().getAttachments().forEach((att) => {
        if (!att.getAttribute('alignment')) {
            const url = urlOfAttachment(att);
            if (url && map[url]) att.setAttributes({ alignment: map[url] });
        }
    });
    // фигуры уже в DOM — проставим классы на следующем кадре
    requestAnimationFrame(() => syncAlignments(editorEl));
});

function updateAlignButtons(editorEl, alignBtns) {
    const att = selectedAttachment(editorEl);
    const cur = att ? (att.getAttribute('alignment') || '') : null;
    alignBtns.forEach((b) => {
        b.disabled = !att;
        b.classList.toggle('trix-active', att && b.dataset.align === cur);
    });
}

// Обработка вложений: загрузка новых файлов + восстановление выравнивания
// импортированных картинок (у которых Trix стёр класс при разборе).
document.addEventListener('trix-attachment-add', (event) => {
    const attachment = event.attachment;

    if (attachment.file) {
        // Новый файл (кнопка «Картинка»/вставка/drag-n-drop): грузим в хранилище
        // вместо base64; прогресс-бар Trix виден прямо на картинке
        attachment.setUploadProgress(5);
        uploadImage(attachment.file, (pct) => attachment.setUploadProgress(pct))
            .then((url) => {
                attachment.setUploadProgress(100);
                attachment.setAttributes({ url, href: url });
            })
            .catch(() => {
                attachment.remove();
                alert('Не удалось загрузить изображение. Проверьте формат и размер (до 8 МБ).');
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
