# Phase 2 — Глубокая проверка и история

## Цель
Сервис проверяет не только главную, а ключевые страницы магазина, показывает устаревшие версии, известные
уязвимости и проблемы SEO; все диагностики можно найти в истории и открыть.

## Статус
🔄 В работе

> План перепроверен 15.11.2026 с учётом сделанного в Фазе 1 (`phase-init.md`).
> Дизайн: образцов нет — экраны из стандартных компонентов Tabler (решение владельца).
> Экраны: форма — `src/Views/pages/home.php`, отчёт — `src/Views/pages/diagnostic.php` (данные готовит
> `src/Services/ReportBuilder.php`), компоненты — `src/Views/components/`.
> JS Tabler подключён в `src/Views/admin/layout/header.php` (`tabler.min.js` включает Bootstrap): модальные
> окна, collapse, dropdown работают. В отчёте раскрытие сделано на нативном `<details>` (работает и без JS).
> Подтверждения — модальные окна Tabler.
> Лимитов обхода нет (решение владельца); технические тайм-ауты остаются константами.
> Источники уязвимостей (решение владельца, 15.11.2026): Wordfence Intelligence (WordPress), retire.js
> (JS-библиотеки), NVD + OSV (ядра CMS), свой список по 1С-Битрикс. WPScan отклонён: 25 запросов в день.
> Уязвимость ищется только по видимой версии; без версии находки `vulnerability` нет.
> Токен Wordfence (`WORDFENCE_API_KEY`) владелец положил в `.env` 16.11.2026 — таск 7 не блокирован.

**Сайты для ручной проверки (дал владелец):**
- https://sudar.su/ — 1С-Битрикс (версия не видна), DDoS-Guard
- https://cleansan.ru/ — Мегагрупп (S3), облачная платформа
- https://www.citilink.ru/ — Next.js, Qrator; воркер не должен падать

## Таски

| # | Название | Статус |
|---|----------|--------|
| 1 | История диагностик | ✅ Завершён |
| 2 | Адрес товара в форме | ✅ Завершён |
| 3 | Поиск ключевых страниц магазина | ✅ Завершён |
| 4 | Проверки на всех страницах и защита от ботов | ✅ Завершён |
| 5 | Базовое SEO | ✅ Завершён |
| 6 | Устаревшие версии и еженедельное обновление баз | ✅ Завершён |
| 7 | Уязвимости: локальные базы | ✅ Завершён |
| 8 | Точность версий и меньше ложных находок | ✅ Завершён |
| 9 | Уязвимости ядер CMS: NVD + OSV | ⏳ Ожидает |

---

## Таск 1 — История диагностик

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler

**Цель таска:**
`/history` (пункт меню уже есть и сейчас ведёт на 404): таблица прошлых диагностик — сайт, дата, статус,
число критичных и высоких находок; поиск по части домена, пагинация, пустое состояние, удаление.

**Что нужно создать/изменить:**
- `src/Models/DiagnosticModel.php` — `searchDiagnostics(query, page)`, `countDiagnostics(query)`, счётчики
  находок по серьёзности, `deleteDiagnostic(id)` (каскад по FK удаляет шаги, страницы, стек, находки)
- `src/Services/HistoryService.php` — нормализация запроса (punycode), пагинация, строки таблицы (чистые функции)
- `src/Controllers/HistoryController.php` — `index`, `delete`
- `src/Views/pages/history.php` — таблица, поиск, пагинация, пустое состояние
- `config/routes.php` — `GET /history`, `POST /diagnostics/{id}/delete`
- `public/assets/js/history.js` — модальное окно Tabler подтверждения удаления: подставляет адрес формы и сайт
- `tests/Unit/HistoryServiceTest.php`
- Сделано сверх плана: кириллический домен на экране (`idn_to_utf8`), `getDiagnosticStatus()` в модели;
  на экране < 576 px пагинация «‹ текущая ›», полный адрес под хостом скрыт

**Definition of Done:**
- [x] Поиск по части домена находит все диагностики хоста
- [x] Удаление — только POST с CSRF после подтверждения; удаляются и связанные записи
- [x] Выполняющуюся (`queued` / `running`) диагностику удалить нельзя — понятное сообщение
- [x] Пустая история (и пустой результат поиска) показывает ссылку «Новая диагностика»
- [x] Пункт меню «История» подсвечен; проверено на 360 / 768 / 1280 px
- [x] Проверить `.docs/dod-global.md`

---

## Таск 2 — Адрес товара в форме

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler

**Цель таска:**
На главной необязательное поле «Адрес товара»: проверяется как ссылка на сайт, хост (без `www.`) должен
совпадать с хостом сайта; пишется в `diagnostics.product_url` (колонка уже есть).

**Что нужно создать/изменить:**
- `src/Services/UrlValidator.php` — `product_url` в `readDiagnosticFormValues()` / `validateDiagnosticInput()`
- `src/Controllers/DiagnosticController.php` — `store()` передаёт `product_url`
- `src/Models/DiagnosticModel.php` — `createDiagnostic()` пишет `product_url`
- `src/Views/pages/home.php` — поле с подсказкой и ошибкой у поля
- `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php` — адрес товара в сводке
- `tests/Unit/UrlValidatorTest.php` — дополнить
- Решения владельца: поддомен не принимается (хост без `www.` должен совпадать); адрес главной (`/` без
  параметров) отклоняется. Схема (http / https) может отличаться от ссылки на сайт

**Definition of Done:**
- [x] Адрес с чужого домена, `ftp://`, мусор → ошибка у поля, введённые значения сохраняются (тест)
- [x] Корректный адрес сохраняется в `product_url` и виден в сводке отчёта
- [x] Пустое поле — диагностика запускается как раньше
- [x] Проверить `.docs/dod-global.md`

---

## Таск 3 — Поиск ключевых страниц магазина

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler

**Цель таска:**
Шаг `crawl` (между `stack` и `resources`) находит категорию, товар, корзину и оформление: по типовым адресам
найденной CMS, по общим адресам, по ссылкам на страницах; адрес товара из формы используется вместо поиска
товара (`found_by = manual`). Найденные страницы — в `checked_pages`, их HTML — в контекст шагов (в БД не пишется).

**Что нужно создать/изменить:**
- `data/shop-patterns.php` — по CMS (WordPress/WooCommerce, 1С-Битрикс, OpenCart, Joomla, PrestaShop, Magento,
  Shop-Script, Мегагрупп, InSales, Tilda) и общие: адреса корзины / оформления, признаки ссылок на категорию и
  товар, признаки страницы товара (`og:type=product`, schema.org `Product`, форма «в корзину») и категории
- `src/Services/ShopCrawler.php` — без БД, сеть параметром: главная → категория → товар; только свой домен,
  без дублей и файлов; остановка, когда найдены все типы или кончились кандидаты; не найден тип — причина
- `src/Services/DiagnosticRunner.php` — шаг `crawl` («Поиск страниц магазина»), heartbeat во время шага
- `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php` — карточка «Проверенные страницы»
  (тип, адрес, код, как найдена; «не найдена» с причиной)
- `tests/Unit/ShopCrawlerTest.php`, фикстуры `tests/fixtures/crawl/<cms>/`
- Сделано сверх плана: `HtmlParser` — ссылки `<a>` (`anchors`: адрес, текст, класс); `buildStepContext()` —
  `product_url`; причины «не найдена» — в `summary_json.pages`; товар из формы с ошибкой → находка `availability`;
  признаки ссылок по классу для платформ (Magento, Мегагрупп); защита от «мягкого 404» у корзины и оформления

**Definition of Done:**
- [x] Для каждой CMS из списка на фикстурах находятся категория и товар; Tilda (каталог строит JS) →
      «не найдена (каталог строится скриптом)» (тест)
- [x] Корзина и оформление находятся по типовым адресам CMS, для неизвестной CMS — по общим адресам / ссылкам (тест)
- [x] Адрес товара из формы используется вместо поиска (тест)
- [x] Обход идёт только по своему домену и останавливается, когда найдены все типы страниц (тест)
- [x] Не найденная страница показана в отчёте «не найдена» с причиной
- [x] Нет главной → шаг `skipped`; битый HTML не роняет шаг (тест)
- [x] Проверено на сайтах владельца
- [x] Проверить `.docs/dod-global.md`

---

## Таск 4 — Проверки на всех страницах и защита от ботов

**Статус:** ✅ Завершён

**Цель таска:**
Ресурсы, PHP-ошибки и mixed content проверяются на каждой найденной странице с общим кэшем проверенных
ресурсов. Страница-заглушка защиты (Cloudflare challenge, DDoS-Guard, Qrator, капча) распознаётся по
содержимому → `blocked = 1`, «не удалось проверить», без ложных находок. В отчёте находки можно смотреть
и по страницам.

**Что нужно создать/изменить:**
- `src/Services/Checks/BotProtectionCheck.php` — признаки заглушек защиты по коду, заголовкам и HTML
- `src/Services/Checks/ResourceCheck.php` — несколько страниц, общий кэш; одна находка на ресурс,
  `page_id` — первая страница, полный список — `evidence.pages`
- `src/Services/DiagnosticRunner.php` — шаг `resources` по всем страницам, флаг `blocked` у страниц
- `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php` — список страниц у находки, пометка
  «не удалось проверить» у заблокированной страницы
- `tests/Unit/BotProtectionCheckTest.php`, `tests/Unit/ResourceCheckTest.php` — дополнить
- Сделано сверх плана: признаки заглушек — данными в `data/bot-protection.php`; PHP-ошибки и mixed content тоже
  объединяются между страницами (`evidence.pages`); название защиты — в `summary_json.blocked`; вкладки находок
  «По серьёзности» / «По страницам»; корзина и оформление с одним адресом проверяются один раз

**Definition of Done:**
- [x] Один и тот же битый ресурс на 3 страницах — одна находка со списком страниц (тест)
- [x] Страница-заглушка Cloudflare → `blocked = 1` и пометка в отчёте, без ложных находок (тест)
- [x] sudar.su (DDoS-Guard, отдаёт настоящую страницу) не помечается `blocked`
- [x] Проверить `.docs/dod-global.md`

---

## Таск 5 — Базовое SEO

**Статус:** ✅ Завершён

**Цель таска:**
Шаг `seo`: robots.txt (доступность, `Disallow: /` для всех), sitemap.xml (из robots или `/sitemap.xml`),
по проверенным страницам — title, description, canonical, `noindex` / `nofollow`, `X-Robots-Tag`.

**Что нужно создать/изменить:**
- `src/Services/Checks/SeoCheck.php` — без БД, сеть параметром
- `src/Services/DiagnosticRunner.php` — шаг `seo`
- `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php` — SEO-факты в сводке
  (robots, sitemap, число адресов в sitemap)
- `tests/Unit/SeoCheckTest.php`, фикстуры
- Сделано сверх плана: серьёзности и правила по типам страниц — одна таблица констант (`SEO_SEVERITY`,
  `SEO_PAGE_RULES`); `HtmlParser` — ключ `title`; sitemap в gzip и обрезанный по размеру; индекс — число файлов и
  адреса первого правильного; находка `sitemap_bad_loc` (`<loc>Array</loc>` у sudar.su); `diagnostic.php` не менялся

**Definition of Done:**
- [x] `noindex` на главной или `Disallow: /` для всех → находка `high` (тест)
- [x] Пустой или одинаковый title на разных страницах → находка `low` (тест)
- [x] canonical на чужой домен → находка `medium` (тест)
- [x] Находка «www и без www — разные сайты» из Фазы 1 не дублируется
- [x] Проверить `.docs/dod-global.md`

---

## Таск 6 — Устаревшие версии и еженедельное обновление баз

**Статус:** ✅ Завершён

**Цель таска:**
Справочник актуальных версий обновляется командой `php bin/update-databases.php` (npm — JS-библиотеки,
Packagist — ядра CMS, api.wordpress.org — ядро WordPress) и **автоматически раз в неделю** через
Планировщик заданий Windows. Шаг `outdated` заполняет `stack_items.latest_version` и создаёт находки
`outdated`; у веток без поддержки (jQuery 1.x / 2.x и т. п.) серьёзность выше. Версии плагинов WordPress —
онлайн с api.wordpress.org с кэшем. При диагностике справочник читается из локального файла.

**Что нужно создать/изменить:**
- `bin/update-databases.php` — CLI: скачивает данные во временный файл, проверяет и только потом заменяет
  старый (сбой источника не портит базу); пишет дату обновления каждой базы и результат в `app.log`;
  повторный запуск, пока идёт прошлый, не стартует (lock-файл)
- `bin/schedule-update-databases.ps1` — регистрирует задачу Планировщика Windows
  «Site Diagnostics — обновление баз»: раз в неделю, PHP из OpenServer
  (`C:\OSPanel\modules\PHP-8.3\PHP\php.exe`), рабочая папка — корень проекта; запускается вручную один раз,
  повторный запуск обновляет задачу, а не создаёт вторую
- `storage/databases/latest-versions.json` — справочник с датой обновления (в `.gitignore`); в репозитории —
  `data/latest-versions.json` как начальный справочник, если обновления ещё не было
- `.gitignore` — `storage/databases/*` (кроме `.gitkeep`)
- `src/Services/VersionService.php` — сравнение версий (`version_compare` с нормализацией), ветки без поддержки
- `src/Services/Checks/OutdatedCheck.php`, `src/Services/DiagnosticRunner.php` — шаг `outdated`
- `src/Models/StackModel.php` — запись `latest_version`
- `src/Services/ReportBuilder.php`, `src/Views/components/stack-item.php` — «актуальная: X»; дата баз в отчёте,
  предупреждение, если базы старше 14 дней (задача Планировщика не отработала)
- `tests/Unit/VersionServiceTest.php`, `tests/Unit/OutdatedCheckTest.php`
- Сделано сверх плана / по-другому: Joomla и Magento — GitHub releases (на Packagist их нет), npm — только
  `/latest` (полный документ React 7 МБ); версия PHP (php.net `active.php` — ветки без поддержки сами);
  реестр баз `getDatabaseSources()` (таск 7 добавляет Wordfence / retire.js записью); `data/version-sources.php`
  — источник и `eol_below` одной строкой; `summary_json.databases` — список баз в отчёте; итог обновления —
  `storage/logs/update-databases.log`; `StackDetector` — `key` у JS-библиотек

**Definition of Done:**
- [x] jQuery 1.x → находка «устарело» с актуальной версией из справочника (тест)
- [x] Без определённой версии находка не создаётся (тест)
- [x] `php bin/update-databases.php` обновляет справочник; сбой источника не портит прошлую базу (тест на разборе)
- [x] Задача Планировщика создана скриптом, видна в «Планировщике заданий», запускается раз в неделю;
      ручной запуск задачи обновляет дату базы
- [x] В отчёте видна дата баз; базы старше 14 дней → предупреждение
- [x] Проверить `.docs/dod-global.md`

---

## Таск 7 — Уязвимости: локальные базы

**Статус:** ✅ Завершён

> Токен Wordfence (`WORDFENCE_API_KEY`) лежит в `.env` (16.11.2026).

**Цель таска:**
Находки `vulnerability` по локальным базам, которые обновляет `bin/update-databases.php` (по расписанию из
таска 6): Wordfence Intelligence v3 — ядро, плагины и темы WordPress (полная база → компактный индекс по slug;
лимит API — 1 запрос в 30 минут, поэтому только скачивание базы, не запросы на каждую диагностику);
retire.js `jsrepository-v4.json` — JS-библиотеки; свой список по 1С-Битрикс из БДУ ФСТЭК и реестра уязвимых
модулей Битрикса. Серьёзность — по CVSS: ≥ 9 `critical`, ≥ 7 `high`, ≥ 4 `medium`, иначе `low`.

**Что нужно создать/изменить:**
- `bin/update-databases.php` — + Wordfence и retire.js (индексы в `storage/databases/`)
- `data/vulnerabilities/bitrix.json` — ядро и модули Битрикса: идентификатор (БДУ / CVE), диапазон версий,
  ссылка на источник, дата
- `src/Services/VulnerabilityService.php` — поиск по индексам, сравнение диапазонов версий
- `src/Services/Checks/VulnerabilityCheck.php`, `src/Services/DiagnosticRunner.php` — шаг `vulnerabilities`
- `.env.example`, страница настроек — `WORDFENCE_API_KEY` вместо `WPSCAN_API_KEY` (маской, только чтение)
- `tests/Unit/VulnerabilityServiceTest.php` — на маленьких фикстурах баз
- Сделано сверх плана / по-другому: Wordfence — полная база `production` одним запросом (в `scanner` нет CVE и
  CVSS), скачивание сразу в файл (`httpDownloadToFile()` в `HttpClient.php`: заголовок `Authorization`, без
  предела 5 МБ) и потоковый разбор (`iterateJsonObjectMembers()`) — `memory_limit` поднимать не понадобилось;
  `VulnerabilityDatabases.php` — разбор Wordfence / retire.js; `data/version-sources.php` — ключ `retire`;
  список Битрикса собран из NVD (БДУ ФСТЭК с ПК недоступна — сертификат российского УЦ); модули Битрикса —
  из поставки (`builtin`) или по пространству имён компонентов; дата базы без дат у записей — общая `updated_at`

**Definition of Done:**
- [x] Уязвимость содержит идентификатор (CVE / БДУ / Wordfence), диапазон затронутых версий, исправленную
      версию и ссылку на источник (тест)
- [x] Без видимой версии находки `vulnerability` нет; модуль Битрикса из реестра уязвимых без версии →
      `info` «модуль из реестра уязвимых решений: проверить версию» (тест)
- [x] Нет базы или нет токена → шаг не падает, в отчёте «база уязвимостей не загружена»
- [x] Для Shop-Script — «нет источника данных»; Мегагрупп, InSales, Tilda — «платформа обновляется провайдером»
- [x] Проверить `.docs/dod-global.md`

---

## Таск 8 — Точность версий и меньше ложных находок

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler

**Цель таска:**
Меньше ложных и шумных находок `outdated` / `vulnerability` (найдено при проверке таска 7: на wpbeginner.com
версия wpforms взята с встроенной библиотеки `jquery.validate.min.js?ver=1.21.0` вместо `wpforms.min.js?ver=2.0.3`
→ ложная уязвимость). Версия плагина / темы — из его собственных файлов, ненадёжная версия не сравнивается;
уязвимости, требующие входа на сайт, — на ступень ниже; находки уязвимостей — одна на элемент стека.

**Что нужно создать/изменить:**
- `src/Services/StackDetector.php` — голосование версий ассетов (`pickVotedVersion()`, `rateAssetVersion()`,
  `rateLibraryVersion()`): файлы библиотек темы / плагина не голосуют, файл с именем плагина — вес 2, ничья → без
  версии; `?ver=` библиотеки в папке темы / плагина — не её версия
- `src/Services/VulnerabilityService.php` — роль из названия записи Wordfence (Administrator+ / Editor+ /
  Shop Manager+ → не выше `low`, Author+ / Contributor+ → ступенью ниже), записи End-of-Life, склонение
- `src/Services/Checks/VulnerabilityCheck.php` — уверенность `low` и темы с общими именами не сверяются; одна
  находка на элемент стека («обновить до», каждая уязвимость в тексте и `evidence.vulnerabilities`)
- `tests/fixtures/stack/wordpress-versions.html`, тесты стека и уязвимостей

**Definition of Done:**
- [x] wpforms на фикстуре wpbeginner — версия 2.0.3, а не 1.21.0 (тест)
- [x] Повторная диагностика wpbeginner.com — без ложной уязвимости wpforms
- [x] Проверить `.docs/dod-global.md`

---

## Таск 9 — Уязвимости ядер CMS: NVD + OSV

**Статус:** ⏳ Ожидает

**Цель таска:**
Для ядра Joomla, Magento, PrestaShop, OpenCart с видимой версией — онлайн-запросы к NVD (по CPE + версии)
и OSV (Packagist), результаты склеиваются по CVE (по отдельности каждый пропускает: OpenCart в NVD — 7 записей
против 17 в OSV, Joomla в OSV — 8 против 125 в NVD). Ответы кэшируются по «CMS + версия».

**Что нужно создать/изменить:**
- `src/Services/NvdClient.php`, `src/Services/OsvClient.php` — запрос, разбор, тайм-аут, кэш в `storage/cache/`
- `src/Services/VulnerabilityService.php` — склейка по CVE с локальными данными
- `src/Services/Checks/VulnerabilityCheck.php` — ядра CMS
- `tests/Unit/NvdClientTest.php`, `tests/Unit/OsvClientTest.php` — на сохранённых ответах

**Definition of Done:**
- [ ] Одна CVE из NVD и OSV — одна находка (тест)
- [ ] Отказ / тайм-аут API не роняет шаг, используется кэш; в отчёте пометка «источник недоступен» (тест)
- [ ] Повторная диагностика той же версии не ходит в API (кэш)
- [ ] Проверить `.docs/dod-global.md`
