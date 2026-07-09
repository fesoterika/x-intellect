// Админка: rich-text редактор Trix — клиентский JS-виджет, встраивается
// в Blade-форму без React/Vue-рантайма (Этап 2 плана)
import 'trix';
import 'trix/dist/trix.css';
// Стили контента редактора — ПОСЛЕ trix.css, чтобы перебить их и preflight
import '../css/trix-content.css';

// Уровни заголовков h2–h5 для контента материалов. H1 в тексте не нужен —
// это заголовок самой страницы; штатная кнопка Heading (h1) скрывается CSS.
document.addEventListener('trix-before-initialize', () => {
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
function uploadImage(file) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('file', file);

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/editor/image', true);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf || '');
        xhr.setRequestHeader('Accept', 'application/json');

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

// Кастомные кнопки на панели редактора: «♪ Аудио» и «🖼 Картинка»
document.addEventListener('trix-initialize', (event) => {
    const editorEl = event.target;
    const toolbar = editorEl.toolbarElement;
    const group = toolbar?.querySelector('.trix-button-group--block-tools');

    if (!group || group.querySelector('[data-x-audio]')) {
        return;
    }

    // Кнопки уровней заголовков H2–H5 — на место штатной кнопки Heading (h1).
    // Клики обрабатывает сам Trix по data-trix-attribute.
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

    // Кнопка вставки аудиоплеера через short-код [[audio:ID]]
    const audioBtn = document.createElement('button');
    audioBtn.type = 'button';
    audioBtn.className = 'trix-button';
    audioBtn.dataset.xAudio = '1';
    audioBtn.textContent = '♪ Аудио';
    audioBtn.title = 'Вставить аудиоплеер — short-код [[audio:ID]]';
    audioBtn.addEventListener('click', () => {
        const id = prompt('ID медиафайла (см. раздел «Медиа»):');
        if (id && /^\d+$/.test(id.trim())) {
            editorEl.editor.insertString(`[[audio:${id.trim()}]]`);
        }
    });
    group.appendChild(audioBtn);

    // Кнопка вставки изображения в позицию курсора: выбор файла → загрузка
    // в хранилище → вставка ссылки (без base64). Alt проставит сервер.
    const imgBtn = document.createElement('button');
    imgBtn.type = 'button';
    imgBtn.className = 'trix-button';
    imgBtn.dataset.xImage = '1';
    imgBtn.textContent = '🖼 Картинка';
    imgBtn.title = 'Вставить изображение в позицию курсора';
    imgBtn.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.style.display = 'none';
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            input.remove();
            if (!file) return;

            imgBtn.disabled = true;
            uploadImage(file)
                .then((url) => {
                    const attachment = new window.Trix.Attachment({
                        content: `<img src="${url}" alt="">`,
                        contentType: file.type,
                    });
                    editorEl.editor.insertAttachment(attachment);
                    editorEl.editor.insertLineBreak();
                })
                .catch(() => alert('Не удалось загрузить изображение. Проверьте формат и размер (до 8 МБ).'))
                .finally(() => { imgBtn.disabled = false; });
        });
        document.body.appendChild(input);
        input.click();
    });
    group.appendChild(imgBtn);
});

// Загрузка вставленных/перетащенных картинок: вместо base64 в тело
// подставляется ссылка на файл в хранилище (диск public).
document.addEventListener('trix-attachment-add', (event) => {
    const attachment = event.attachment;

    // Только вложения с файлом (вставка/drag-n-drop); вставки кнопкой
    // «Картинка» уже содержат готовый url и файла не имеют
    if (!attachment.file) {
        return;
    }

    attachment.setUploadProgress(10);
    uploadImage(attachment.file)
        .then((url) => {
            attachment.setUploadProgress(100);
            attachment.setAttributes({ url, href: url });
        })
        .catch(() => {
            attachment.remove(); // не оставляем base64 при ошибке
            alert('Не удалось загрузить изображение. Проверьте формат и размер (до 8 МБ).');
        });
});
