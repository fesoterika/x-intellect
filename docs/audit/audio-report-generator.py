#!/usr/bin/env python3
"""Отчёт: аудио в архиве Offline2015 vs аудио на новом сайте (dev-БД SQLite)."""
import os, re, sqlite3, html, urllib.parse
from collections import defaultdict
from datetime import date

ARCHIVE = '/Users/woronokin_macmini/Downloads/Offline2015/www.x-intellect.org/www.x-intellect.org'
WIKI = ARCHIVE + '/wiki'
AUDIO_DIR = ARCHIVE + '/files/audio'
DB = '/Users/woronokin_macmini/X-Intellect/x-intellect/database/database.sqlite'
OUT = '/Users/woronokin_macmini/X-Intellect/x-intellect/docs/audit/audio-report.md'

# Дополнительные источники (сверка 2026-07-15): ищем недостающие файлы и здесь
EXTRA_DIRS = [
    '/Volumes/KINGSTON/Philipp Toshiba/Источники/Сайт x-intellect.org/Архив Дмитрия Морозова',
    '/Volumes/KINGSTON/Philipp Toshiba/Источники/Сайт x-intellect.org/WIKI',
]

# ── БД ────────────────────────────────────────────────────────────────────
con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row
pages = {r['id']: dict(r) for r in con.execute(
    "SELECT p.id, p.title, p.slug, p.status, p.section_id, s.slug AS section_slug, s.parent_id "
    "FROM pages p LEFT JOIN sections s ON s.id = p.section_id")}
sections = {r['id']: dict(r) for r in con.execute("SELECT id, slug, parent_id FROM sections")}

def page_url(p):
    sec = sections.get(p['section_id'])
    if not sec:
        return '/' + p['slug']
    root = sections.get(sec['parent_id']) or sec
    return f"/{root['slug']}/{p['slug']}"

by_title = {}
for p in pages.values():
    by_title.setdefault(re.sub(r'\s+', ' ', p['title']).strip(), p)

site_audio = defaultdict(list)  # page_id -> [basename]
for r in con.execute("SELECT page_id, file_path FROM media WHERE type='audio'"):
    site_audio[r['page_id']].append(os.path.basename(r['file_path']))
site_files = {f for lst in site_audio.values() for f in lst}

# ── Архив: mp3 на диске ───────────────────────────────────────────────────
archive_files = {}  # basename -> relpath
for root, _, files in os.walk(AUDIO_DIR):
    for f in files:
        if f.lower().endswith('.mp3'):
            archive_files[f] = os.path.relpath(os.path.join(root, f), ARCHIVE)

# ── Архив: вики-страницы со ссылками на mp3 ──────────────────────────────
ref_re = re.compile(r'(?:\.\./)*files/audio/[^\s"\'<>]+?\.mp3', re.I)
h1_re = re.compile(r'<h1[^>]*id="firstHeading"[^>]*>(.*?)</h1>', re.S)
ns_re = re.compile(r'"wgNamespaceNumber":(-?\d+)')

wiki_refs = {}  # title -> set(basenames)
for name in os.listdir(WIKI):
    if not name.startswith('index.php@title=') or '&' in name:
        continue
    if re.search(r'\.(png|jpe?g|gif|svg|mp3|pdf|css|js|tmp|ico|webp|bmp)$', name, re.I):
        continue
    path = os.path.join(WIKI, name)
    if not os.path.isfile(path):
        continue
    try:
        text = open(path, encoding='utf-8', errors='replace').read()
    except OSError:
        continue
    m = ns_re.search(text)
    if m and m.group(1) != '0':
        continue
    refs = ref_re.findall(text)
    if not refs:
        continue
    m = h1_re.search(text)
    if not m:
        continue
    title = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', m.group(1)))).strip()
    if not title:
        continue
    # Служебные виды MediaWiki (action=edit): «Просмотр исходного текста
    # страницы X» — это та же страница X, ссылки учитываем за неё
    title = re.sub(r'^Просмотр исходного текста страницы\s+', '', title)
    basenames = {os.path.basename(urllib.parse.unquote(r)) for r in refs}
    wiki_refs.setdefault(title, set()).update(basenames)

# ── Сверка ────────────────────────────────────────────────────────────────
missing_on_pages = []   # (title, page|None, [дорожки, которых нет на сайте], [дорожек нет и в архиве])
ok_pages = 0
for title, refs in sorted(wiki_refs.items()):
    p = by_title.get(title)
    have = set(site_audio.get(p['id'], [])) if p else set()
    missing = sorted(refs - have)
    if not missing:
        ok_pages += 1
        continue
    lost = [f for f in missing if f not in archive_files]  # ссылка была, файла нет и в слепке
    missing_on_pages.append((title, p, missing, lost))

# Дополнительные папки: имя файла -> путь
extra_files = {}
for base in EXTRA_DIRS:
    if not os.path.isdir(base):
        continue
    for root, _, files in os.walk(base):
        for f in files:
            if f.lower().endswith('.mp3'):
                extra_files.setdefault(f, os.path.join(root, f))

# mp3 из архива, не попавшие ни на одну страницу сайта
referenced = {f for refs in wiki_refs.values() for f in refs}
orphans = sorted(f for f in archive_files if f not in site_files)

def guess_page(fname):
    """Вероятная страница для непривязанного mp3: по дате YYYYMMDD в имени."""
    m = re.search(r'(20\d{6}|19\d{6})', fname)
    if not m:
        return None
    d = m.group(1)
    cands = [p for p in pages.values() if d in p['title']]
    return cands[0] if cands else None

pages_with_audio = sum(1 for v in site_audio.values() if v)

def fmt_page(title, p):
    if not p:
        return f'**{title}** — страницы нет на сайте'
    return f'[{title}]({page_url(p)}) ({p["status"]})'

# ── Markdown ──────────────────────────────────────────────────────────────
lines = [
    '# Аудио: архив ↔ сайт',
    '',
    f'> Сгенерировано {date.today().isoformat()} сверкой слепка Offline2015',
    '> (`files/audio/**` + ссылки в вики-страницах) с dev-БД (`media`, type=audio).',
    '',
    '## Итого',
    '',
    f'- mp3 в архиве (files/audio/**): **{len(archive_files)}**',
    f'- дорожек на сайте (media, audio): **{len(site_files)}** на {pages_with_audio} страницах',
    f'- вики-страниц архива со ссылками на аудио: **{len(wiki_refs)}**, из них полностью перенесено: **{ok_pages}**',
    f'- страниц, где аудио должно быть, но отсутствует: **{len(missing_on_pages)}**',
    f'- mp3 архива, не привязанных ни к одной странице сайта: **{len(orphans)}**',
    '',
    '## Страницы, где в архиве было аудио, а на сайте его нет',
    '',
    '> Личные консультации при импорте намеренно оставлены без аудио',
    '> (см. archive-import-notes.md, Фаза C) — решение можно пересмотреть здесь.',
    '',
]
if not missing_on_pages:
    lines.append('Все найденные в архиве привязки перенесены.')
else:
    for title, p, missing, lost in missing_on_pages:
        lines.append(f'- {fmt_page(title, p)}')
        for f in missing:
            note = ' — **файла нет и в слепке** (ссылка битая уже в архиве)' if f in lost else ''
            if f in extra_files:
                note += f' — **найден в доп. источнике**: `{extra_files[f]}`'
            elif f in archive_files:
                note += ' — файл лежит в слепке (не привязан намеренно)'
            lines.append(f'  - `{f}`{note}')
lines += [
    '',
    '### Сверка с дополнительными источниками (2026-07-15)',
    '',
    '- «Архив Дмитрия Морозова» и «WIKI» (диск KINGSTON) проверены по всем недостающим файлам.',
    '- `20120907_talismani_i_amuleti_2.mp3` найден в `WIKI/2012` — **добавлен на страницу**',
    '  «Сеанс с Силами 20120907» (media id 88).',
    '- Аудио личных консультаций (20100612, 20100722, 20110421, 20120524a, 20130312) в этих',
    '  папках нет; их файлы взяты из слепка Offline2015 и **привязаны к страницам**',
    '  повторным прогоном `import:offline-audio` (по решению от 2026-07-15; ранее',
    '  оставались без привязки намеренно).',
]
lines += ['', '## mp3 из архива, не привязанные ни к одной странице сайта', '']
if not orphans:
    lines.append('Таких файлов нет.')
else:
    # группировка по подпапке
    groups = defaultdict(list)
    for f in orphans:
        rel = archive_files[f]
        sub = os.path.dirname(rel).replace('files/audio', '').strip('/') or '(корень)'
        groups[sub].append(f)
    for sub in sorted(groups):
        lines.append(f'### files/audio/{sub}' if sub != '(корень)' else '### files/audio (корень)')
        lines.append('')
        for f in sorted(groups[sub]):
            # какая архивная страница ссылалась / вероятная страница по дате
            refs_from = [t for t, refs in wiki_refs.items() if f in refs]
            if refs_from:
                suffix = f' — ссылка со страницы «{refs_from[0]}»'
            else:
                p = guess_page(f)
                suffix = f' — вероятно, страница [{p["title"]}]({page_url(p)})' if p else ''
            lines.append(f'- `{f}`{suffix}')
        lines.append('')

open(OUT, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
print(f'написано: {OUT}')
print(f'архив: {len(archive_files)} mp3; сайт: {len(site_files)}; страниц с пропусками: {len(missing_on_pages)}; сирот: {len(orphans)}')
