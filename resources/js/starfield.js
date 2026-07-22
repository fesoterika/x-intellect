/**
 * Звёздное небо тёмной темы.
 *
 * Рисуется на canvas поверх туманности, заданной градиентами в CSS. Прежний
 * вариант — два замощённых слоя из stars.svg — мигал целиком и повторялся
 * заметной сеткой; здесь у каждой звезды своя фаза и скорость мерцания,
 * поэтому повторов нет вовсе. CSS-слои остаются запасным вариантом для
 * страниц без JS (заглушка техработ): их скрывает класс is-canvas.
 *
 * Поверх неба — созвездие в форме знака проекта (кольцо сферы, орбита,
 * скрещённые лучи, звезда в зените): пропорции из favicon.svg, сжатые
 * по высоте, чтобы фигура ложилась в широкую полосу шапки.
 */

/* Прозрачность линий фигуры по назначению: лучи заметнее всего,
   орбита едва намечена — знак читается, но не спорит с текстом. */
const LINE_ALPHA = { main: 0.25, ring: 0.11, orbit: 0.06 };

const FIGURE_TINT = '221, 209, 255';
const STAR_TINTS = ['255, 255, 255', '201, 186, 255', '255, 220, 174'];

/**
 * Точки и линии знака в долях единичного квадрата.
 * Исходное поле — 100×100, как в favicon.svg: кольцо R=37.9,
 * орбита-эллипс 35.9×13.5, лучи по вершинам кольца.
 */
function buildFigure() {
    const C = 50;
    const R = 37.9;
    const EX = 35.9;
    const EY = 13.5;
    const SQUASH = 0.8; // сжатие по высоте: ширина к высоте как 1 : 0,8

    const pts = [];
    const edges = [];

    // Кольцо: зенит, четыре конца лучей (±29.5°), бока и надир. Промежутки
    // неравные — иначе фигура читается как чертёжный многоугольник.
    [0, 29.5, 90, 150.5, 180, 209.5, 270, 330.5].forEach((deg) => {
        const a = (deg * Math.PI) / 180;
        pts.push([C + R * Math.sin(a), C - R * Math.cos(a), 0.8]);
    });
    for (let i = 0; i < 8; i++) edges.push([i, (i + 1) % 8, 'ring']);

    // Орбита. Сдвиг на 30° нужен, чтобы её вершины не сливались с боками кольца.
    // Точки орбиты (по возрастанию угла): 0 — право-верх, 1 — центр-верх,
    // 2 — лево-верх, 3 — лево-низ, 4 — центр-низ, 5 — право-низ.
    const orbit0 = pts.length;
    for (let i = 0; i < 6; i++) {
        const t = ((30 + i * 60) * Math.PI) / 180;
        pts.push([C + EX * Math.cos(t), C + EY * Math.sin(t), 0.6]);
    }
    // Кольцо шире орбиты, поэтому по бокам эллипс замыкается не сам на себя,
    // а на боковые звёзды кольца (2 — восток/право, 6 — запад/лево): иначе
    // левый и правый края орбиты выглядят обрезанными.
    edges.push(
        [orbit0 + 0, orbit0 + 1, 'orbit'],
        [orbit0 + 1, orbit0 + 2, 'orbit'],
        [orbit0 + 2, 6, 'orbit'],
        [6, orbit0 + 3, 'orbit'],
        [orbit0 + 3, orbit0 + 4, 'orbit'],
        [orbit0 + 4, orbit0 + 5, 'orbit'],
        [orbit0 + 5, 2, 'orbit'],
        [2, orbit0 + 0, 'orbit'],
    );

    // Лучи: две диагонали через центр, концы лежат на кольце
    const center = pts.length;
    pts.push([C, C, 1.6]);
    edges.push([7, center, 'main'], [center, 3, 'main'], [1, center, 'main'], [center, 5, 'main']);

    pts[0][2] = 2.3; // зенитная звезда — точка знака, крупнее прочих
    [1, 3, 5, 7].forEach((i) => { pts[i][2] = 1.5; });

    pts.forEach((p) => { p[1] = C + (p[1] - C) * SQUASH; });

    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    const scale = Math.max(Math.max(...xs) - minX, Math.max(...ys) - minY);

    return {
        pts: pts.map((p) => [(p[0] - minX) / scale, (p[1] - minY) / scale, p[2]]),
        edges,
    };
}

/** Спрайт ореола: рисовать градиент заново каждый кадр слишком дорого. */
function haloSprite(rgb) {
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const ctx = canvas.getContext('2d');
    const grad = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    grad.addColorStop(0, `rgba(${rgb},1)`);
    grad.addColorStop(1, `rgba(${rgb},0)`);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);

    return canvas;
}

export default function initStarfield() {
    const host = document.querySelector('.starfield');
    if (!host || typeof window.requestAnimationFrame !== 'function') return;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    host.appendChild(canvas);
    host.classList.add('is-canvas');

    const figure = buildFigure();
    const halos = {};
    STAR_TINTS.forEach((rgb) => { halos[rgb] = haloSprite(rgb); });
    const figureHalo = haloSprite(FIGURE_TINT);

    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    let reduced = motionQuery.matches;

    let width = 0;
    let height = 0;
    let stars = [];
    let raf = null;
    let meteor = null;
    let nextMeteor = 6;

    function seed() {
        stars = [];
        const count = Math.round((width * height) / 5000);
        for (let i = 0; i < count; i++) {
            const big = Math.random() >= 0.82;
            const roll = Math.random();
            stars.push({
                x: Math.random() * width,
                y: Math.random() * height,
                r: big ? 1.1 + Math.random() * 0.9 : 0.5 + Math.random() * 0.7,
                base: 0.17 + Math.random() * 0.45,
                amp: 0.08 + Math.random() * 0.25,
                spd: 0.4 + Math.random() * 1.1,
                ph: Math.random() * Math.PI * 2,
                // Примерно каждая десятая звезда — с мягким ореолом, как в прежнем фоне
                halo: big && Math.random() < 0.55,
                tint: roll < 0.1 ? STAR_TINTS[2] : (roll < 0.32 ? STAR_TINTS[1] : STAR_TINTS[0]),
            });
        }
    }

    function resize() {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = host.clientWidth;
        height = host.clientHeight;
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        seed();
    }

    function figurePoint(p) {
        const side = Math.min(width * 0.26, height * 0.52);
        return [width * 0.63 + p[0] * side, height * 0.12 + p[1] * side];
    }

    function drawFigure(time) {
        ctx.lineWidth = 1;
        ['orbit', 'ring', 'main'].forEach((kind) => {
            ctx.strokeStyle = `rgba(155, 134, 236, ${LINE_ALPHA[kind]})`;
            ctx.beginPath();
            figure.edges.forEach((edge) => {
                if (edge[2] !== kind) return;
                const a = figurePoint(figure.pts[edge[0]]);
                const b = figurePoint(figure.pts[edge[1]]);
                ctx.moveTo(a[0], a[1]);
                ctx.lineTo(b[0], b[1]);
            });
            ctx.stroke();
        });

        figure.pts.forEach((p, i) => {
            const [x, y] = figurePoint(p);
            const weight = p[2];
            const tw = reduced ? 0.72 : 0.68 + 0.18 * Math.sin(i * 1.7 + time * 0.5);
            const crown = i === 0; // зенитная звезда — точка знака

            // Ореол крупнее у зенитной звезды, ядро мягче — свечение вместо чёткой точки
            const radius = (4 + 6 * weight) * (crown ? 1.5 : 1);
            ctx.globalAlpha = tw * (crown ? 0.3 : 0.22);
            ctx.drawImage(figureHalo, x - radius, y - radius, radius * 2, radius * 2);
            ctx.globalAlpha = 1;

            ctx.beginPath();
            ctx.arc(x, y, weight * (crown ? 0.7 : 1), 0, Math.PI * 2);
            ctx.fillStyle = `rgba(${FIGURE_TINT}, ${(tw * (crown ? 0.6 : 0.78)).toFixed(3)})`;
            ctx.fill();
        });
    }

    function drawMeteor(time) {
        if (!meteor && time > nextMeteor) {
            meteor = {
                x: width * (0.15 + Math.random() * 0.6),
                y: height * (0.05 + Math.random() * 0.2),
                vx: 260 + Math.random() * 120,
                vy: 130 + Math.random() * 70,
                life: 0,
                prev: time,
            };
        }
        if (!meteor) return;

        const dt = time - meteor.prev;
        meteor.prev = time;
        meteor.x += meteor.vx * dt;
        meteor.y += meteor.vy * dt;
        meteor.life += dt;

        const fade = Math.max(0, 1 - meteor.life / 0.9);
        const tail = 70 * fade;
        const tailY = (tail * meteor.vy) / meteor.vx;
        const grad = ctx.createLinearGradient(meteor.x, meteor.y, meteor.x - tail, meteor.y - tailY);
        grad.addColorStop(0, `rgba(255, 255, 255, ${(0.8 * fade).toFixed(3)})`);
        grad.addColorStop(1, 'rgba(255, 255, 255, 0)');

        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.4;
        ctx.beginPath();
        ctx.moveTo(meteor.x, meteor.y);
        ctx.lineTo(meteor.x - tail, meteor.y - tailY);
        ctx.stroke();

        if (meteor.life > 0.9 || meteor.x > width + 80 || meteor.y > height + 80) {
            meteor = null;
            nextMeteor = time + 10 + Math.random() * 8;
        }
    }

    function frame(now) {
        const time = now / 1000;
        ctx.clearRect(0, 0, width, height);
        drawFigure(time);

        for (let i = 0; i < stars.length; i++) {
            const star = stars[i];
            const alpha = reduced
                ? star.base
                : Math.max(0.05, star.base + star.amp * Math.sin(star.ph + time * star.spd));

            if (star.halo) {
                const radius = star.r * 7;
                ctx.globalAlpha = alpha * 0.26;
                ctx.drawImage(halos[star.tint], star.x - radius, star.y - radius, radius * 2, radius * 2);
                ctx.globalAlpha = 1;
            }

            ctx.beginPath();
            ctx.arc(star.x, star.y, star.r, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(${star.tint}, ${alpha.toFixed(3)})`;
            ctx.fill();
        }

        if (reduced) {
            raf = null;
            return;
        }

        drawMeteor(time);
        raf = window.requestAnimationFrame(frame);
    }

    function start() {
        if (raf !== null) return;
        resize();
        raf = window.requestAnimationFrame(frame);
    }

    function stop() {
        if (raf !== null) {
            window.cancelAnimationFrame(raf);
            raf = null;
        }
        ctx.clearRect(0, 0, width, height);
    }

    function sync() {
        if (document.documentElement.getAttribute('data-theme') === 'dark') start();
        else stop();
    }

    // Пересев звёзд при каждом кадре ресайза не нужен — ждём, пока размер устоится
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        if (document.documentElement.getAttribute('data-theme') !== 'dark') return;
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            resize();
            if (reduced) window.requestAnimationFrame(frame);
        }, 200);
    });

    window.addEventListener('xi-theme', sync);

    if (typeof motionQuery.addEventListener === 'function') {
        motionQuery.addEventListener('change', (event) => {
            reduced = event.matches;
            stop();
            sync();
        });
    }

    sync();
}
