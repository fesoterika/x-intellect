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
