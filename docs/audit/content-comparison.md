# Аудит контента: новый сайт vs архив

Сформировано: 14.07.2026 22:41. Команда: `php artisan audit:archive`.

Исключены из сверки (по требованию): раздел сайта «Библиотека» (library), форум. Страницы `source_type=new` — не сверяются с архивом (созданы для нового сайта).

## Сводка

| Что | В архиве (содержательное) | Есть на сайте | Отсутствует |
|---|---|---|---|
| Основной сайт (страницы WordPress) | 64 | 64 | 0 |
| Вики (статьи ns-0) | 163 | 163 | 0 |

### Сверка количества с Wayback Machine

| Источник | Заголовков вики |
|---|---|
| Офлайн-слепок 2015 (ns-0, содержательные) | 163 |
| Wayback CDX (2010–2024, чистые title-URL) | 112 |
| БД: страницы вики (archive_wiki) | 130 |
| БД: термины глоссария | 79 |

Есть только в Wayback (нет ни в слепке, ни в БД): **1**

| Заголовок | Снимок |
|---|---|
| Хроносфера и временной фактор | https://web.archive.org/web/20200926191839/http://www.x-intellect.org/wiki/index.php?title=%D0%A5%D1%80%D0%BE%D0%BD%D0%BE%D1%81%D1%84%D0%B5%D1%80%D0%B0_%D0%B8_%D0%B2%D1%80%D0%B5%D0%BC%D0%B5%D0%BD%D0%BD%D0%BE%D0%B9_%D1%84%D0%B0%D0%BA%D1%82%D0%BE%D1%80 |

## Основной сайт

### Отсутствующие на новом сайте

Нет — все содержательные страницы слепка есть на сайте.

### Подозрительно короткие (возможно, заполнены не полностью)

| Страница | Текст в архиве | Текст на сайте | Отметка |
|---|---|---|---|
| [Ченнелинг: Мнение Сил о сайте X-INTELLECT](http://localhost/about/2012-07-08) | 563 | 227 | короче архива на 60% |
| [Проект эталонизации физиологических систем](http://localhost/articles/2013-01-06-models) | 788 | 451 | короче архива на 43% |
| [Исполнилось 40 дней со дня перехода Александра Георгиевича Глаза в иные реальности безграничного Дома Вселенной](http://localhost/about/ispolnilos-40-dnei-so-dnia-perexoda-aleksandra-georgievica-glaza-v-inye-realnosti-bezgranicnogo-doma-vselennoi) | 871 | 472 | короче архива на 46% |
| [Дайджест «X-INTELLECT». Октябрь 2012](http://localhost/articles/daidzest-x-intellect-oktiabr-2012) | 1021 | 684 | короче архива на 33% |
| [Дайджест «X-INTELLECT». Сентябрь 2012](http://localhost/articles/daidzest-x-intellect-sentiabr-2012) | 1133 | 794 | короче архива на 30% |
| [Проект «Душа» (продолжение,ч.4)](http://localhost/projects/dusa-4) | 1084 | 758 | короче архива на 30% |
| [Проект «Душа» (продолжение, часть 2)](http://localhost/projects/dusha-karma-2) | 851 | 510 | короче архива на 40% |
| [Проект «Душа» (продолжение, часть 3)](http://localhost/projects/dusha-matrix-3) | 953 | 615 | короче архива на 35% |
| [Проект Картины Учителей Ноосферы: Вода (часть 2/2)](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-voda-cast-22) | 1222 | 882 | короче архива на 28% |
| [Проект «Душа»](http://localhost/projects/proect-dusha) | 868 | 550 | короче архива на 37% |
| [Сегодня Александру Глазу исполнилось бы 53 года…но Творцу было угодно, чтобы душа его устремилась к небесам…](http://localhost/projects/segodnya-aleksandru-glazu-ispolnilos-by-53-goda-no-tvortsu-by-lo-ugodno-chtoby-dusha-ego-ustremilas-k-nebesam) | 1047 | 645 | короче архива на 38% |
| [Вопрос — Oтвет: Тема «Внеземные летательные аппараты и мы»](http://localhost/articles/vopros-otvet-tema-vnezemnye-letatelnye-apparaty-i-my) | 889 | 528 | короче архива на 41% |

### Полный список (архив → сайт)

| Архивная страница | На сайте | Статус |
|---|---|---|
| День Рождения Александра Глаза | [2012-07-04-2](http://localhost/about/2012-07-04-2) | draft |
| День Рождения Александра Глаза | [2012-07-04](http://localhost/about/2012-07-04) | draft |
| Ченнелинг: Мнение Сил о сайте X-INTELLECT | [2012-07-08](http://localhost/about/2012-07-08) | draft |
| Проект эталонизации физиологических систем | [2013-01-06-models](http://localhost/articles/2013-01-06-models) | published |
| Безвременно ушел от нас Александр Георгиевич Глаз | [bezvremenno-usel-ot-nas-aleksandr-georgievic-glaz](http://localhost/about/bezvremenno-usel-ot-nas-aleksandr-georgievic-glaz) | draft |
| Проект «Целительство» | [297](http://localhost/projects/297) | draft |
| Исполнилось 40 дней со дня перехода Александра Георгиевича Глаза в иные реальности безграничного Дома Вселенной | [ispolnilos-40-dnei-so-dnia-perexoda-aleksandra-georgievica-glaza-v-inye-realnosti-bezgranicnogo-doma-vselennoi](http://localhost/about/ispolnilos-40-dnei-so-dnia-perexoda-aleksandra-georgievica-glaza-v-inye-realnosti-bezgranicnogo-doma-vselennoi) | draft |
| СВЕТЛАЯ ПАМЯТЬ АЛЕКСАНДРУ ГЛАЗУ | [svetlaia-pamiat-aleksandru-glazu](http://localhost/about/svetlaia-pamiat-aleksandru-glazu) | draft |
| Глаз Александр Георгиевич | [alexandrglaz](http://localhost/about/alexandrglaz) | draft |
| Тема: Камни | [camni-000-09-2012](http://localhost/articles/camni-000-09-2012) | draft |
| «Взаимодействие и манипуляция энергетическими центрами человека» 2 Кольцо | [vzaimodeistvie-i-manipuliaciia-energeticeskimi-centrami-celoveka-2-kolco](http://localhost/courses/vzaimodeistvie-i-manipuliaciia-energeticeskimi-centrami-celoveka-2-kolco) | draft |
| Статья: Некоторые методики чистки после воздействия деструктивных сил | [statia-nekotorye-metodiki-cistki-posle-vozdeistviia-destruktivnyx-sil](http://localhost/articles/statia-nekotorye-metodiki-cistki-posle-vozdeistviia-destruktivnyx-sil) | draft |
| Дайджест «X-INTELLECT» №4. Декабрь 2012 | [daidzest-x-intellect-4-dekabr-2012](http://localhost/articles/daidzest-x-intellect-4-dekabr-2012) | draft |
| Дайджест «Х-INTELLECT» (Апрель — 2013) | [daidzest-x-intellect-aprel-2013](http://localhost/articles/daidzest-x-intellect-aprel-2013) | draft |
| «Дайджест Х-INTELLECT» (декабрь 2012 г.) | [daidzest-x-intellect-dekabr-2012-g](http://localhost/articles/daidzest-x-intellect-dekabr-2012-g) | draft |
| О схемах воздействия Деструктивных Сил (ДС) на человека | [o-sxemax-vozdeistviia-destruktivnyx-sil-ds-na-celoveka](http://localhost/articles/o-sxemax-vozdeistviia-destruktivnyx-sil-ds-na-celoveka) | draft |
| «Дайджест Х-INTELLECT» (ноябрь 2012) | [daidzest-x-intellect-noiabr-2012](http://localhost/articles/daidzest-x-intellect-noiabr-2012) | draft |
| Дайджест «X-INTELLECT». Октябрь 2012 | [daidzest-x-intellect-oktiabr-2012](http://localhost/articles/daidzest-x-intellect-oktiabr-2012) | draft |
| «Дайджест Х-INTELLECT» (октябрь 2012) | [daidzest-x-intellect-oktiabr-2012-2](http://localhost/articles/daidzest-x-intellect-oktiabr-2012-2) | draft |
| Дайджест «X-INTELLECT». Сентябрь 2012 | [daidzest-x-intellect-sentiabr-2012](http://localhost/articles/daidzest-x-intellect-sentiabr-2012) | draft |
| «Дайджест Х-INTELLECT» (сентябрь 2012) | [daidzest-x-intellect-sentiabr-2012-2](http://localhost/articles/daidzest-x-intellect-sentiabr-2012-2) | draft |
| Проект «Душа» (продолжение,ч.4) | [dusa-4](http://localhost/projects/dusa-4) | draft |
| Проект «ДУША». Люди и животные (Приложение, часть 4) | [dusha-animals-4](http://localhost/projects/dusha-animals-4) | draft |
| Проект «Душа» (продолжение, часть 2) | [dusha-karma-2](http://localhost/projects/dusha-karma-2) | draft |
| Проект «Душа» (продолжение, часть 3) | [dusha-matrix-3](http://localhost/projects/dusha-matrix-3) | draft |
| Проект «ДУША»: Вопросы и ответы (по информации из предыдущих ченнелингов) | [dusha-prodoljenie](http://localhost/projects/dusha-prodoljenie) | draft |
| Проект «Душа» (продолжение) | [dusha-sens-syst-2](http://localhost/projects/dusha-sens-syst-2) | draft |
| Сенсорные системы и системы представления знаний: Монография | [dusha-sens-syst-mon](http://localhost/library/dusha-sens-syst-mon) | draft |
| Сенсорные системы и система представления знаний: Монография | [dusha-sens-syst](http://localhost/library/dusha-sens-syst) | draft |
| Приветствие от представителей Внеземного Разума | [privetstvie](http://localhost/hello/privetstvie) | published |
| Статья: Инкарнационная ячейка | [statia-inkarnacionnaia-iaceika](http://localhost/articles/statia-inkarnacionnaia-iaceika) | draft |
| Статья: Практика на основе «Цветок Лотоса» | [lotos](http://localhost/articles/lotos) | draft |
| Проект «Мужчина и Женщина» ч.2 | [manandwuman2](http://localhost/projects/manandwuman2) | draft |
| Проект «Мужчина и Женщина» 2 Кольцо | [mf2k](http://localhost/courses/mf2k) | draft |
| Проект «Мужчина и Женщина» ч.3 | [proekt-muzcina-i-zenshhina-c3](http://localhost/projects/proekt-muzcina-i-zenshhina-c3) | draft |
| Проект «Ноосфера» (начало: Что такое Ноосфера?) | [noosfera](http://localhost/projects/noosfera) | draft |
| Проект «Ноосфера-1» Структура Ноосферы | [nsf22](http://localhost/projects/nsf22) | draft |
| Проект «Ноосфера-2» Инкарнационные ячейки | [proekt-noosfera-2-inkarnacionnye-iaceiki](http://localhost/projects/proekt-noosfera-2-inkarnacionnye-iaceiki) | draft |
| Основные правила грамматики русского языка | [opgry](http://localhost/rules/opgry) | draft |
| Дружеская беседа с Силами 2 Кольца по организационным вопросам | [org-2k](http://localhost/courses/org-2k) | draft |
| Проект «Изосфера и параллельные миры — 3″ | [parallel-ny-e-miry-proekt-prodolzhenie](http://localhost/projects/parallel-ny-e-miry-proekt-prodolzhenie) | draft |
| Проект «Изосфера и параллельные миры»,ч.2 | [proekt-izosfera-i-parallelnye-miryc2](http://localhost/projects/proekt-izosfera-i-parallelnye-miryc2) | draft |
| Проект «Картины Учителей Ноосферы: Воздух» | [proekt-kartiny-ucitelei-noosfery-vozdux](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-vozdux) | draft |
| Проект «Картины Учителей Ноосферы: Земля» | [proekt-kartiny-ucitelei-noosfery-zemlia](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-zemlia) | draft |
| Проект «Картины Учителей Ноосферы: Огонь» | [proekt-kartiny-ucitelei-noosfery-ogon](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-ogon) | draft |
| Проект Картины Учителей Ноосферы: Вода (часть 1/2) | [proekt-kartiny-ucitelei-noosfery-voda-cast-12](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-voda-cast-12) | draft |
| Проект Картины Учителей Ноосферы: Вода (часть 2/2) | [proekt-kartiny-ucitelei-noosfery-voda-cast-22](http://localhost/projects/proekt-kartiny-ucitelei-noosfery-voda-cast-22) | draft |
| Проект «Мужчина и Женщина» ч.4 | [proekt-muzcina-i-zenshhina-c4](http://localhost/projects/proekt-muzcina-i-zenshhina-c4) | draft |
| Проект «Душа» | [proect-dusha](http://localhost/projects/proect-dusha) | draft |
| Проект «Изосфера и параллельные миры -4″ | [proekt-izosfera-i-parallel-ny-e-miry-4](http://localhost/projects/proekt-izosfera-i-parallel-ny-e-miry-4) | draft |
| Проект «Мужчина и Женщина -5» О любви | [proekt-muzhchina-i-zhenshhina-ch-5](http://localhost/projects/proekt-muzhchina-i-zhenshhina-ch-5) | draft |
| Проект «Ноосфера -5» Кто такие Учителя | [proekt-noosfera-5-kto-takie-uchitelya](http://localhost/projects/proekt-noosfera-5-kto-takie-uchitelya) | draft |
| Проект «Ноосфера -6» Взаимодействие Ноосферы с людьми | [proekt-noosfera-6-vzaimodejstvie-noosfery-s-lyud-mi](http://localhost/projects/proekt-noosfera-6-vzaimodejstvie-noosfery-s-lyud-mi) | draft |
| Проект «Ноосфера-3» Инкарнационные ячейки | [proekt-noosfera-prodolzhenie-3](http://localhost/projects/proekt-noosfera-prodolzhenie-3) | draft |
| Проект «Ноосфера -4» Кто такие Учителя | [proekt-noosfera-prodolzhenie-4](http://localhost/projects/proekt-noosfera-prodolzhenie-4) | draft |
| Проект «Изосфера и параллельные миры» | [proekt-izosfera-i-parallelnye-miry](http://localhost/projects/proekt-izosfera-i-parallelnye-miry) | draft |
| Проект «Биоэкран: Физиология» | [proekt-bioekran-fiziologiia](http://localhost/projects/proekt-bioekran-fiziologiia) | draft |
| Проект «Мужчина и Женщина», ч.1 | [proekt-muzcina-i-zenshhina-c1](http://localhost/projects/proekt-muzcina-i-zenshhina-c1) | draft |
| Вопрос — ответ (проект: Целительство) | [questions](http://localhost/projects/questions) | draft |
| Сегодня Александру Глазу исполнилось бы 53 года…но Творцу было угодно, чтобы душа его устремилась к небесам… | [segodnya-aleksandru-glazu-ispolnilos-by-53-goda-no-tvortsu-by-lo-ugodno-chtoby-dusha-ego-ustremilas-k-nebesam](http://localhost/projects/segodnya-aleksandru-glazu-ispolnilos-by-53-goda-no-tvortsu-by-lo-ugodno-chtoby-dusha-ego-ustremilas-k-nebesam) | draft |
| Ченнелинг: Талисманы и амулеты | [talisman](http://localhost/articles/talisman) | draft |
| Статья: Тандемы. Посредники и ведущие. | [tandem-posrednik-vedushiy](http://localhost/articles/tandem-posrednik-vedushiy) | draft |
| Тема: О «частоте Шумана» и не только о ней | [tema-o-castote-sumana-i-ne-tolko-o-nei](http://localhost/articles/tema-o-castote-sumana-i-ne-tolko-o-nei) | draft |
| Вопрос — Oтвет: Тема «Внеземные летательные аппараты и мы» | [vopros-otvet-tema-vnezemnye-letatelnye-apparaty-i-my](http://localhost/articles/vopros-otvet-tema-vnezemnye-letatelnye-apparaty-i-my) | draft |

## Вики

### Отсутствующие (нет ни страницы, ни термина)

Нет — все статьи ns-0 слепка представлены на сайте (страницей или термином глоссария).

### Подозрительно короткие

Нет.

### Полный список (архив → сайт)

| Статья вики | На сайте | Как |
|---|---|---|
| Активация и гармонизация чакр | [aktivaciia-i-garmonizaciia-cakr](http://localhost/aktivaciia-i-garmonizaciia-cakr) | страница (draft) |
| Акупунктурные точки | [глоссарий](http://localhost/glossary?term=akupunkturnye-tocki) | термин глоссария |
| Арсенал памяти больших полушарий | [глоссарий](http://localhost/glossary?term=arsenal-pamiati-bolsix-polusarii) | термин глоссария |
| Аудиозапись 20130704 | [audiozapis-20130704](http://localhost/audiozapis-20130704) | страница (draft) |
| Библиотека | [biblioteka](http://localhost/biblioteka) | страница (draft) |
| Биоэкран | [bioekran](http://localhost/bioekran) | страница (draft) |
| Ближний Космос (Силы Ближнего Космоса) | [bliznii-kosmos-sily-bliznego-kosmosa](http://localhost/bliznii-kosmos-sily-bliznego-kosmosa) | страница (draft) |
| Блок видовых программ мозжечка | [глоссарий](http://localhost/glossary?term=blok-vidovyx-programm-mozzecka) | термин глоссария |
| Блуждающие импульсы | [глоссарий](http://localhost/glossary?term=bluzdaiushhie-impulsy) | термин глоссария |
| Верхний конус биоэкрана | [verxnii-konus-bioekrana](http://localhost/verxnii-konus-bioekrana) | страница (draft) |
| Внеземные Цивилизации (ВЦ) | [vnezemnye-tsivilizatsii](http://localhost/vnezemnye-tsivilizatsii) | страница (draft) |
| Вращающийся диск биоэкрана | [глоссарий](http://localhost/glossary?term=vrashhaiushhiisia-disk-bioekrana) | термин глоссария |
| Временные (темпоральные) тоннели | [глоссарий](http://localhost/glossary?term=vremennye-temporalnye-tonneli) | термин глоссария |
| Временные (темпоральные) факторы | [глоссарий](http://localhost/glossary?term=vremennye-temporalnye-faktory) | термин глоссария |
| Временные (темпоральные) ключи | [глоссарий](http://localhost/glossary?term=vremennye-temporalnye-kliuci) | термин глоссария |
| Временные (темпоральные) оси | [глоссарий](http://localhost/glossary?term=vremennye-temporalnye-osi) | термин глоссария |
| Временные факторы | [глоссарий](http://localhost/glossary?term=vremennye-faktory) | термин глоссария |
| Голубые | [golubye](http://localhost/golubye) | страница (draft) |
| Дайджест "X-INTELLECT". Сентябрь 2012 | [daidzest-x-intellect-sentiabr-2012-3](http://localhost/daidzest-x-intellect-sentiabr-2012-3) | страница (published) |
| Дальний Космос (Силы Дальнего Космоса) | [глоссарий](http://localhost/glossary?term=dalnii-kosmos-sily-dalnego-kosmosa) | термин глоссария |
| Двойник, энергоинформационный (астральный двойник, оболочечный двойник) | [глоссарий](http://localhost/glossary?term=dvoinik-energoinformacionnyi-astralnyi-dvoinik-obolocecnyi-dvoinik) | термин глоссария |
| Душа | [dusa](http://localhost/dusa) | страница (draft) |
| Жизненное кредо | [глоссарий](http://localhost/glossary?term=ziznennoe-kredo) | термин глоссария |
| Защита от Деструктивных Сил (ДС) | [zashhita-ot-destruktivnyx-sil-ds](http://localhost/zashhita-ot-destruktivnyx-sil-ds) | страница (draft) |
| Зеленые | [zelenye](http://localhost/zelenye) | страница (draft) |
| Зеркально отражённые стабилизирующие оси | [глоссарий](http://localhost/glossary?term=zerkalno-otrazennye-stabiliziruiushhie-osi) | термин глоссария |
| Импульсы до востребования | [глоссарий](http://localhost/glossary?term=impulsy-do-vostrebovaniia) | термин глоссария |
| Инкарнационные фильтры | [глоссарий](http://localhost/glossary?term=inkarnacionnye-filtry) | термин глоссария |
| Инкарнационная информация | [глоссарий](http://localhost/glossary?term=inkarnacionnaia-informaciia) | термин глоссария |
| Инкарнационный луч | [глоссарий](http://localhost/glossary?term=inkarnacionnyi-luc) | термин глоссария |
| Инкарнационная ячейка | [глоссарий](http://localhost/glossary?term=inkarnacionnaia-iaceika) | термин глоссария |
| Инкарнация | [глоссарий](http://localhost/glossary?term=inkarnaciia) | термин глоссария |
| Инки | [inki](http://localhost/inki) | страница (draft) |
| Информационно-энергетическая структура мозжечка | [глоссарий](http://localhost/glossary?term=informacionno-energeticeskaia-struktura-mozzecka) | термин глоссария |
| История создания | [istoriia-sozdaniia](http://localhost/istoriia-sozdaniia) | страница (draft) |
| Картины Учителей Ноосферы: 2012 | [kartiny-ucitelei-noosfery-2012](http://localhost/kartiny-ucitelei-noosfery-2012) | страница (draft) |
| Координаторы | [koordinatory](http://localhost/koordinatory) | страница (draft) |
| Космические Силы | [глоссарий](http://localhost/glossary?term=kosmiceskie-sily) | термин глоссария |
| Красные квант-глюинные пары | [глоссарий](http://localhost/glossary?term=krasnye-kvant-gliuinnye-pary) | термин глоссария |
| Кредовое кольцо биоэкрана | [kredovoe-kolco-bioekrana](http://localhost/kredovoe-kolco-bioekrana) | страница (draft) |
| Кредовое кольцо биоэкрана (кредовое кольцо полевого мозга) | [глоссарий](http://localhost/glossary?term=kredovoe-kolco-bioekrana-kredovoe-kolco-polevogo-mozga) | термин глоссария |
| Кредовые программы | [глоссарий](http://localhost/glossary?term=kredovye-programmy) | термин глоссария |
| Курация | [глоссарий](http://localhost/glossary?term=kuraciia) | термин глоссария |
| Лечение с помощью психо-биоэнергетического воздействия на биоактивные точки | [lecenie-s-pomoshhiu-psixo-bioenergeticeskogo-vozdeistviia-na-bioaktivnye-tocki](http://localhost/lecenie-s-pomoshhiu-psixo-bioenergeticeskogo-vozdeistviia-na-bioaktivnye-tocki) | страница (draft) |
| Луч 6-й чакры (базового энергоцентра) | [глоссарий](http://localhost/glossary?term=luc-6-i-cakry-bazovogo-energocentra) | термин глоссария |
| Луч инкарнационый | [глоссарий](http://localhost/glossary?term=luc-inkarnacionyi) | термин глоссария |
| Меднокожие | [mednokozie](http://localhost/mednokozie) | страница (draft) |
| Меридианное поле | [глоссарий](http://localhost/glossary?term=meridiannoe-pole) | термин глоссария |
| Меридианы | [глоссарий](http://localhost/glossary?term=meridiany) | термин глоссария |
| Мерности | [глоссарий](http://localhost/glossary?term=mernosti) | термин глоссария |
| Метагалактический домен | [глоссарий](http://localhost/glossary?term=metagalakticeskii-domen) | термин глоссария |
| Нитевидные энергетические структуры биоэкрана | [глоссарий](http://localhost/glossary?term=nitevidnye-energeticeskie-struktury-bioekrana) | термин глоссария |
| Ноосфера | [глоссарий](http://localhost/glossary?term=noosfera) | термин глоссария |
| Ноосферная инкарнационная ячейка | [глоссарий](http://localhost/glossary?term=noosfernaia-inkarnacionnaia-iaceika) | термин глоссария |
| Нулевой меридиан | [глоссарий](http://localhost/glossary?term=nulevoi-meridian) | термин глоссария |
| Общий энергофон | [глоссарий](http://localhost/glossary?term=obshhii-energofon) | термин глоссария |
| Параллельные миры | [глоссарий](http://localhost/glossary?term=parallelnye-miry) | термин глоссария |
| Первичные излучения, первоизлучения | [глоссарий](http://localhost/glossary?term=pervicnye-izluceniia-pervoizluceniia) | термин глоссария |
| Перешеек биоэкрана | [глоссарий](http://localhost/glossary?term=pereseek-bioekrana) | термин глоссария |
| План тренинга: "ЭНЕРГЕТИЧЕСКОЕ ВИДЕНИЕ" | [plan-treninga-energeticeskoe-videnie](http://localhost/plan-treninga-energeticeskoe-videnie) | страница (draft) |
| Подготовка Посредников | [podgotovka-posrednikov](http://localhost/podgotovka-posrednikov) | страница (draft) |
| Подчерепной энергококон | [глоссарий](http://localhost/glossary?term=podcerepnoi-energokokon) | термин глоссария |
| Полевая оболочка | [глоссарий](http://localhost/glossary?term=polevaia-obolocka) | термин глоссария |
| Полевая структура в виде ниспадающего «водопада» | [глоссарий](http://localhost/glossary?term=polevaia-struktura-v-vide-nispadaiushhego-vodopada) | термин глоссария |
| Правила Википедии | [pravila-vikipedii](http://localhost/pravila-vikipedii) | страница (draft) |
| Программа | [глоссарий](http://localhost/glossary?term=programma) | термин глоссария |
| Программы | [programmy](http://localhost/programmy) | страница (draft) |
| Проекты 2005 - 2012 | [proekty-2005-2012](http://localhost/proekty-2005-2012) | страница (draft) |
| Проект Биоэкран. Часть 4. | [proekt-bioekran-cast-4](http://localhost/proekt-bioekran-cast-4) | страница (draft) |
| Проект Биоэкран. Часть 2. | [proekt-bioekran-cast-2](http://localhost/proekt-bioekran-cast-2) | страница (draft) |
| Проект Биоэкран. Часть 1. | [proekt-bioekran-cast-1](http://localhost/proekt-bioekran-cast-1) | страница (draft) |
| Проект Душа. Часть 2. | [proekt-dusa-cast-2](http://localhost/proekt-dusa-cast-2) | страница (draft) |
| Проект Душа. Часть 3. | [proekt-dusa-cast-3](http://localhost/proekt-dusa-cast-3) | страница (draft) |
| Проект Душа. Часть 5. | [proekt-dusa-cast-5](http://localhost/proekt-dusa-cast-5) | страница (draft) |
| Проект Душа. Часть 1. | [proekt-dusa-cast-1](http://localhost/proekt-dusa-cast-1) | страница (draft) |
| Проект Картины Учителей Ноосферы: Воздух | [proekt-kartiny-ucitelei-noosfery-vozdux-2](http://localhost/proekt-kartiny-ucitelei-noosfery-vozdux-2) | страница (draft) |
| Проект Картины Учителей Ноосферы: Земля | [proekt-kartiny-ucitelei-noosfery-zemlia-2](http://localhost/proekt-kartiny-ucitelei-noosfery-zemlia-2) | страница (draft) |
| Проект Картины Учителей Ноосферы: Вода. Часть 1 | [proekt-kartiny-ucitelei-noosfery-voda-cast-1](http://localhost/proekt-kartiny-ucitelei-noosfery-voda-cast-1) | страница (draft) |
| Проект Картины Учителей Ноосферы: Вода. Часть 2 | [proekt-kartiny-ucitelei-noosfery-voda-cast-2](http://localhost/proekt-kartiny-ucitelei-noosfery-voda-cast-2) | страница (draft) |
| Проект Картины Учителей Ноосферы: Огонь | [proekt-kartiny-ucitelei-noosfery-ogon-2](http://localhost/proekt-kartiny-ucitelei-noosfery-ogon-2) | страница (draft) |
| Разведка Дальнего Космоса | [razvedka-dalnego-kosmosa](http://localhost/razvedka-dalnego-kosmosa) | страница (draft) |
| Развитие энергоинформационного восприятия | [razvitie-energoinformacionnogo-vospriiatiia](http://localhost/razvitie-energoinformacionnogo-vospriiatiia) | страница (draft) |
| Резонирующее кольцо биоэкрана | [глоссарий](http://localhost/glossary?term=rezoniruiushhee-kolco-bioekrana) | термин глоссария |
| Реинкарнация | [глоссарий](http://localhost/glossary?term=reinkarnaciia) | термин глоссария |
| Рейшей | [глоссарий](http://localhost/glossary?term=reisei) | термин глоссария |
| Ромбовидная линза | [глоссарий](http://localhost/glossary?term=rombovidnaia-linza) | термин глоссария |
| Светлая Память Александр Глаз | [svetlaia-pamiat-aleksandr-glaz](http://localhost/svetlaia-pamiat-aleksandr-glaz) | страница (draft) |
| Сеансы 1991 - 2008 | [seansy-1991-2008](http://localhost/seansy-1991-2008) | страница (draft) |
| Сеансы 2009 - 2010 | [seansy-2009-2010](http://localhost/seansy-2009-2010) | страница (draft) |
| Сеансы 2011 | [seansy-2011](http://localhost/seansy-2011) | страница (draft) |
| Сеансы 2012 | [seansy-2012](http://localhost/seansy-2012) | страница (draft) |
| Сеансы 2013 | [seansy-2013](http://localhost/seansy-2013) | страница (draft) |
| Сеанс с Силами 20120907 | [seans-s-silami-20120907](http://localhost/seans-s-silami-20120907) | страница (draft) |
| Сеанс с Силами 20121129 | [seans-s-silami-20121129](http://localhost/seans-s-silami-20121129) | страница (draft) |
| Сеанс с Силами 20121201 | [seans-s-silami-20121201](http://localhost/seans-s-silami-20121201) | страница (draft) |
| Сеанс с Силами 20121217 | [seans-s-silami-20121217](http://localhost/seans-s-silami-20121217) | страница (draft) |
| Сеанс с Силами 20130103 | [seans-s-silami-20130103](http://localhost/seans-s-silami-20130103) | страница (draft) |
| Сеанс с Силами 20130105 | [seans-s-silami-20130105](http://localhost/seans-s-silami-20130105) | страница (draft) |
| Сеанс с Силами 20130113 | [seans-s-silami-20130113](http://localhost/seans-s-silami-20130113) | страница (draft) |
| Сеанс с Силами 20130118 | [seans-s-silami-20130118](http://localhost/seans-s-silami-20130118) | страница (draft) |
| Сеанс с Силами 20130119 | [seans-s-silami-20130119](http://localhost/seans-s-silami-20130119) | страница (draft) |
| Сеанс с Силами 20130126 | [seans-s-silami-20130126](http://localhost/seans-s-silami-20130126) | страница (draft) |
| Сеанс с Силами 20130209 | [seans-s-silami-20130209](http://localhost/seans-s-silami-20130209) | страница (draft) |
| Сеанс с Силами 20130211 | [seans-s-silami-20130211](http://localhost/seans-s-silami-20130211) | страница (draft) |
| Сеанс с Силами 20130223 | [seans-s-silami-20130223](http://localhost/seans-s-silami-20130223) | страница (draft) |
| Сеанс с Силами 20130301 | [seans-s-silami-20130301](http://localhost/seans-s-silami-20130301) | страница (draft) |
| Сеанс с Силами 20130302 | [seans-s-silami-20130302](http://localhost/seans-s-silami-20130302) | страница (draft) |
| Сеанс с Силами 20130303 | [seans-s-silami-20130303](http://localhost/seans-s-silami-20130303) | страница (draft) |
| Сеанс с Силами 20130311 | [seans-s-silami-20130311](http://localhost/seans-s-silami-20130311) | страница (draft) |
| Сеанс с Силами 20130404 | [seans-s-silami-20130404](http://localhost/seans-s-silami-20130404) | страница (draft) |
| Сеанс с Силами 20130414 | [seans-s-silami-20130414](http://localhost/seans-s-silami-20130414) | страница (draft) |
| Сеанс с Силами 20130428 | [seans-s-silami-20130428](http://localhost/seans-s-silami-20130428) | страница (draft) |
| Сеанс с Силами 20130505 | [seans-s-silami-20130505](http://localhost/seans-s-silami-20130505) | страница (draft) |
| Сеанс с Силами 20130511 | [seans-s-silami-20130511](http://localhost/seans-s-silami-20130511) | страница (draft) |
| Сеанс с Силами 20130512 | [seans-s-silami-20130512](http://localhost/seans-s-silami-20130512) | страница (draft) |
| Сеанс с Силами 20130513 | [seans-s-silami-20130513](http://localhost/seans-s-silami-20130513) | страница (draft) |
| Сеанс с Силами 20130518 | [seans-s-silami-20130518](http://localhost/seans-s-silami-20130518) | страница (draft) |
| Сеанс с Силами 20130519 | [seans-s-silami-20130519](http://localhost/seans-s-silami-20130519) | страница (draft) |
| Сеанс с Силами 20130623 | [seans-s-silami-20130623](http://localhost/seans-s-silami-20130623) | страница (draft) |
| Сеанс с Силами 20130630 | [seans-s-silami-20130630](http://localhost/seans-s-silami-20130630) | страница (draft) |
| Сеанс с силами 20101114 | [seans-s-silami-20101114](http://localhost/seans-s-silami-20101114) | страница (draft) |
| Сеанс с силами 20120120 | [seans-s-silami-20120120](http://localhost/seans-s-silami-20120120) | страница (draft) |
| Сеанс с силами 20120708 | [seans-s-silami-20120708](http://localhost/seans-s-silami-20120708) | страница (draft) |
| Сеанс с силами 20120714 | [seans-s-silami-20120714](http://localhost/seans-s-silami-20120714) | страница (draft) |
| Сеанс с силами 20120722 | [seans-s-silami-20120722](http://localhost/seans-s-silami-20120722) | страница (draft) |
| Сеанс с силами 20120726a | [seans-s-silami-20120726a](http://localhost/seans-s-silami-20120726a) | страница (draft) |
| Сеанс с силами 20120726b | [seans-s-silami-20120726b](http://localhost/seans-s-silami-20120726b) | страница (draft) |
| Сеанс с силами 20120726c | [seans-s-silami-20120726c](http://localhost/seans-s-silami-20120726c) | страница (draft) |
| Сеанс с силами 20120726d | [seans-s-silami-20120726d](http://localhost/seans-s-silami-20120726d) | страница (draft) |
| Сеанс с силами 20120727a | [seans-s-silami-20120727a](http://localhost/seans-s-silami-20120727a) | страница (draft) |
| Сеанс с силами 20120729a | [seans-s-silami-20120729a](http://localhost/seans-s-silami-20120729a) | страница (draft) |
| Сеанс с силами 20120729b | [seans-s-silami-20120729b](http://localhost/seans-s-silami-20120729b) | страница (draft) |
| Сеанс с силами 20120729c | [seans-s-silami-20120729c](http://localhost/seans-s-silami-20120729c) | страница (draft) |
| Сеанс с силами 20120729d | [seans-s-silami-20120729d](http://localhost/seans-s-silami-20120729d) | страница (draft) |
| Сеанс с силами 20120729e | [seans-s-silami-20120729e](http://localhost/seans-s-silami-20120729e) | страница (draft) |
| Смерть | [глоссарий](http://localhost/glossary?term=smert) | термин глоссария |
| Соборная Душа Разума | [глоссарий](http://localhost/glossary?term=sobornaia-dusa-razuma) | термин глоссария |
| Стабилизирующие оси больших полушарий и биоэкрана | [глоссарий](http://localhost/glossary?term=stabiliziruiushhie-osi-bolsix-polusarii-i-bioekrana) | термин глоссария |
| Стабилизирующие оси | [stabiliziruiushhie-osi](http://localhost/stabiliziruiushhie-osi) | страница (draft) |
| Сторожевые импульсы | [глоссарий](http://localhost/glossary?term=storozevye-impulsy) | термин глоссария |
| Сторожевые мозжечковые импульсы | [глоссарий](http://localhost/glossary?term=storozevye-mozzeckovye-impulsy) | термин глоссария |
| Суперструна | [глоссарий](http://localhost/glossary?term=superstruna) | термин глоссария |
| Темпоральная энергия | [глоссарий](http://localhost/glossary?term=temporalnaia-energiia) | термин глоссария |
| Тетрады энергетических копий хромосом (лепестков) | [глоссарий](http://localhost/glossary?term=tetrady-energeticeskix-kopii-xromosom-lepestkov) | термин глоссария |
| Техника астральной сборки оболочечного двойника | [texnika-astralnoi-sborki-obolocecnogo-dvoinika](http://localhost/texnika-astralnoi-sborki-obolocecnogo-dvoinika) | страница (draft) |
| Техники | [texniki](http://localhost/texniki) | страница (draft) |
| Торы биоэкрана | [глоссарий](http://localhost/glossary?term=tory-bioekrana) | термин глоссария |
| Тор темпоральный | [глоссарий](http://localhost/glossary?term=tor-temporalnyi) | термин глоссария |
| Точка сборки (ТС) | [глоссарий](http://localhost/glossary?term=tocka-sborki-ts) | термин глоссария |
| УФО | [ufo](http://localhost/ufo) | страница (draft) |
| Установочные линзы таламуса | [глоссарий](http://localhost/glossary?term=ustanovocnye-linzy-talamusa) | термин глоссария |
| Учителя | [глоссарий](http://localhost/glossary?term=ucitelia) | термин глоссария |
| Чакры | [глоссарий](http://localhost/glossary?term=cakry) | термин глоссария |
| Черные | [cernye](http://localhost/cernye) | страница (draft) |
| Шамбала | [глоссарий](http://localhost/glossary?term=sambala) | термин глоссария |
| Эгрегор | [глоссарий](http://localhost/glossary?term=egregor) | термин глоссария |
| Эмоция | [глоссарий](http://localhost/glossary?term=emociia) | термин глоссария |
| Энергетические мосты (энергомосты) | [глоссарий](http://localhost/glossary?term=energeticeskie-mosty-energomosty) | термин глоссария |
| Энергетические «улитки» | [глоссарий](http://localhost/glossary?term=energeticeskie-ulitki) | термин глоссария |
| Энергетический дубликат полевой оболочки (оболочечный двойник, астральный двойник) | [глоссарий](http://localhost/glossary?term=energeticeskii-dublikat-polevoi-obolocki-obolocecnyi-dvoinik-astralnyi-dvoinik) | термин глоссария |
| Энергетические пятна (энергопятна) | [глоссарий](http://localhost/glossary?term=energeticeskie-piatna-energopiatna) | термин глоссария |
| Энергоинформационный двойник человека (“двойник над головой”) | [глоссарий](http://localhost/glossary?term=energoinformacionnyi-dvoinik-celoveka-dvoinik-nad-golovoi) | термин глоссария |
| Ядро инкарнационной ячейки | [глоссарий](http://localhost/glossary?term=iadro-inkarnacionnoi-iaceiki) | термин глоссария |

## Страницы нового сайта (source_type=new) — не сверяются

| Страница | Раздел | Статус |
|---|---|---|
| [История проекта: от «Сферы Разума» до X-Intellect](http://localhost/about/istoriya-proekta) | О центре | published |
| [Архивы курсов Александра Глаза](http://localhost/courses/arhivy-kursov-aleksandra-glaza) | Курсы | published |
| [Ф. (@fesoterika)](http://localhost/fesoterika) | О центре | published |
| [Политика конфиденциальности](http://localhost/rules/politika-konfidencialnosti) | Правила | draft |
| [Политика использования Cookies](http://localhost/rules/politika-cookies) | Правила | draft |
| [Правила проекта](http://localhost/rules/pravila-proekta) | Правила | published |
| [Ресурсы проекта](http://localhost/about/contacts) | О центре | published |

## Редиректы

Недостающих редиректов со старых адресов: **11** (создано --fix-redirects: 11)

Редиректов с битой целью (внутренний адрес не существует): **0**

## Известные ограничения

- Страницы, импортированные из Wayback Machine, — без картинок (в снимках это внешние URL веб-архива; чистильщик их не тянет).
- Ручное редактирование в Trix убирает `id`-якоря из тела (ограничение редактора); якоря сохраняются при импорте и программных правках.
- «Личные консультации» намеренно не импортируются (правило импортёра) — перечислены в extra-pages.md.
