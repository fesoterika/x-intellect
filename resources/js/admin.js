// Админка: rich-text редактор Trix — клиентский JS-виджет, встраивается
// в Blade-форму без React/Vue-рантайма (Этап 2 плана)
import 'trix';
import 'trix/dist/trix.css';

// Кастомная кнопка «♪ Аудио»: вставляет в текст short-код [[audio:ID]],
// который разворачивается в аудиоплеер при рендере публичной страницы
document.addEventListener('trix-initialize', (event) => {
    const toolbar = event.target.toolbarElement;
    const group = toolbar?.querySelector('.trix-button-group--block-tools');

    if (!group || group.querySelector('[data-x-audio]')) {
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'trix-button';
    button.dataset.xAudio = '1';
    button.textContent = '♪ Аудио';
    button.title = 'Вставить аудиоплеер — short-код [[audio:ID]]';

    button.addEventListener('click', () => {
        const id = prompt('ID медиафайла (см. раздел «Медиа»):');

        if (id && /^\d+$/.test(id.trim())) {
            event.target.editor.insertString(`[[audio:${id.trim()}]]`);
        }
    });

    group.appendChild(button);
});

// Загрузка вставленных/перетащенных картинок в хранилище: вместо base64
// в тело подставляется ссылка на файл. Alt проставляется на сервере при
// сохранении страницы (App\Services\ImageSeo).
document.addEventListener('trix-attachment-add', (event) => {
    const attachment = event.attachment;

    // Только вложения с файлом (вставка/drag-n-drop картинки)
    if (!attachment.file) {
        return;
    }

    const form = new FormData();
    form.append('file', attachment.file);

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/editor/image', true);
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf || '');
    xhr.setRequestHeader('Accept', 'application/json');

    // Прогресс загрузки — Trix показывает индикатор на вложении
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            attachment.setUploadProgress((e.loaded / e.total) * 100);
        }
    });

    xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                const data = JSON.parse(xhr.responseText);
                attachment.setAttributes({ url: data.url, href: data.url });
                return;
            } catch (e) { /* провал парсинга — обработаем ниже */ }
        }
        attachment.remove(); // не оставляем base64 при ошибке
        alert('Не удалось загрузить изображение. Проверьте формат и размер (до 8 МБ).');
    });

    xhr.addEventListener('error', () => {
        attachment.remove();
        alert('Ошибка сети при загрузке изображения.');
    });

    xhr.send(form);
});
