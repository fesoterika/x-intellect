/**
 * Совместимость со старыми браузерами.
 *
 * Нижняя граница бандла — Safari 10.1 / iOS 10.3: это первые версии с
 * поддержкой <script type="module">, а Vite собирает только модули. Всё, что
 * старше (Safari 9, iOS 9, iOS 10.0-10.2), этот файл просто не загружает —
 * там работает ES5-деградация из layouts/site.blade.php.
 *
 * Здесь только точечные полифиллы того, чем реально пользуется сайт. core-js
 * и @vitejs/plugin-legacy сознательно НЕ подключены: они добавили бы к сборке
 * сотню килобайт ради вымершей доли устройств, а требование к работе —
 * не утяжелять основной сайт.
 */

// Alpine 3 планирует реактивные обновления через queueMicrotask (Safari 12.1+,
// iOS 12.2+). Без него Alpine.start() падает, а вместе с ним умирают плеер,
// мобильное меню и все x-* директивы на странице.
if (typeof window.queueMicrotask !== 'function') {
    window.queueMicrotask = function (callback) {
        Promise.resolve()
            .then(callback)
            // queueMicrotask по спецификации не глотает исключения, а сообщает
            // о них как о необработанных — воспроизводим это поведение
            .catch((error) => setTimeout(() => { throw error; }, 0));
    };
}

/**
 * localStorage в приватном режиме старых iOS присутствует, но бросает
 * QuotaExceededError на КАЖДУЮ запись. Плеер пишет позицию раз в ~5 секунд,
 * так что без защиты исключение летит всё время воспроизведения.
 */
export const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    },

    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            // приватный режим или переполненное хранилище — позиция
            // прослушивания просто не сохранится, воспроизведению не мешает
        }
    },

    remove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {
            // см. выше
        }
    },
};
