# Dev Log

Журнал рабочих сессий. Свежие записи — сверху, сразу под этим блоком.

---

## Формат записи

**Дата:** ДД.ММ.ГГГГ
**Что сделано:** ...
**Что следующее:** ...

---

**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 5 «Базовое SEO» ✅. Перед началом — коммит таска 4 (`05ffeb6`).
- `Checks/SeoCheck.php` (без БД, сеть параметром), шаг `seo` «Базовое SEO» — последний, после `resources`. Серьёзности — таблица `SEO_SEVERITY`, что проверяется на каком типе страницы — `SEO_PAGE_RULES` (правка одной строкой, тесты читают серьёзность из таблицы).
- robots.txt: 404 / HTML вместо текста → `low`, 5xx / нет ответа → `medium`; `Disallow: /` для `*` / Yandex / Googlebot → `high` с именами роботов (пустой `Disallow:` и `Allow: /` в группе — не запрет; несколько `User-agent` подряд — одна группа); заглушка защиты от ботов → «не удалось проверить».
- sitemap: из `Sitemap:` robots, иначе `/sitemap.xml`; gzip распаковывается; обрезанный по размеру считается по тегам; индекс — число файлов + адреса первого правильного вложенного. Нет → `low` (в т. ч. HTML-страница по типовому адресу), из robots не открывается / битый XML → `medium`, пустой → `low`, записи `<loc>` не адреса → `medium` (`sitemap_bad_loc`).
- Страницы (главная + найденные `crawl`, без заглушек и ответов не 2xx; корзина и оформление не проверяются): нет title, одинаковый title, нет description — по одной находке `low` со списком страниц; canonical на другой хост (без `www.`) → `medium`; `noindex` (meta robots / yandex / googlebot, `X-Robots-Tag`, `none`): главная `high`, категория / товар `medium`; `nofollow` на главной / категории → `medium`. www / без www шаг не проверяет (находка Фазы 1 не дублируется).
- `HtmlParser` + ключ `title`; `DiagnosticRunner::runSeoStep()` — итоги в `summary_json.seo`; `ReportBuilder::buildReportSeoRows()` — строки «robots.txt», «sitemap.xml», «Адресов в sitemap» в «Сводке» (у старых диагностик нет). `diagnostic.php` не менялся.
- Найдено вживую: у sudar.su в sitemap-индексе `<loc>Array</loc>` (ошибка кода сайта) → добавлена находка `sitemap_bad_loc`, регрессионный тест. cleansan.ru — sitemap `.xml.gz` из robots, 50000 адресов; citilink.ru — индекс из 26 файлов, 809 адресов в первом; title / description / canonical / noindex у всех трёх в порядке. Экран — 360 / 768 / 1280 px, консоль чистая. Тесты — 480 OK на PHP 8.3 OpenServer (+ `SeoCheckTest` 30 тестов с фикстурами `tests/fixtures/seo/`, `HtmlParserTest`, `DiagnosticRunnerTest`, `ReportBuilderTest`). Тестовые диагностики удалены, `app.log` пуст.
- Заметка: `JSON_MERGE_PATCH` в `updateDiagnosticSummary()` не сохраняет поля со значением null — отчёт читает их через `??`.
**Что следующее:** Phase 2, таск 6 «Устаревшие версии и еженедельное обновление баз» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 2, таск 4 «Проверки на всех страницах и защита от ботов» ✅. Перед началом — коммит таска 3 (`7e8f12c`).
- `data/bot-protection.php` + `Checks/BotProtectionCheck.php` (`detectBotProtection()`, без сети и БД): Cloudflare, DDoS-Guard, Qrator, капча, общие «Checking your browser». Заглушка сразу — по заголовку (`cf-mitigated: challenge`) или признаку, который бывает только на заглушке (`window._cf_chl_opt`, `<title>DDoS-Guard</title>`, `/__qrator/qauth`, `/showcaptcha?`); по «слабым» признакам (скрипт защиты, виджет капчи) — только на почти пустой странице (≤ 600 символов видимого текста). Заголовков CDN и кода 403/503 мало.
- `ResourceCheck.php`: `runResourceCheck()` по всем страницам (`collectResourcePages()`: главная + `facts.crawl.pages`, без дублей по итоговому адресу, без страниц без ответа, заглушки пропускаются); общий кэш адресов, страницы ресурса — напрямую и через CSS (`resolveResourcePageIndexes()`); одна находка на ресурс, `page_id` — первая страница, все — `evidence.pages`; сообщение «Проверено страниц: N, не удалось: M; ресурсов …».
- `DiagnosticRunner.php`: `blocked` при записи страниц в `runAvailabilityStep()` / `runCrawlStep()`, название и признак — в `summary_json.blocked` (id страницы → `{title, reason}`); `addPageErrorFindings($result, $context)` — PHP-ошибки и mixed content по всем проверенным страницам, `mergePageFindings()` (PHP-ошибка — по типу, файлу, строке; остальное — по fingerprint).
- Отчёт: у страницы бейдж «не удалось проверить» с защитой и признаком; у находки с нескольких страниц — список «Страницы (N)»; вкладки Tabler «По серьёзности» / «По страницам» (группа на адрес, корзина = оформление — одна группа, «Сайт целиком» — находки без страницы); предупреждение в сводке, если главная — заглушка.
- Исправлено после живой проверки sudar.su: пустая корзина `/checkout/` (80 символов текста, скрипт SmartCaptcha на всех страницах) ложно считалась капчей → признаки капчи (`thin_markup`) ищутся в разметке без `<script>` / `<style>`; регрессионный тест. Порог текста снижен с 1500 до 600 (страница контактов с формой и reCAPTCHA ~1400 символов).
- Проверено вживую: sudar.su — 4 страницы, ни одна не `blocked`, 12 битых (6 общих для 3–4 страниц, 6 — 403 у ресурсов `sale.order.ajax` на оформлении, повторяется); cleansan.ru — 4 страницы, 13 битых картинок на всех; citilink.ru — 3 страницы, воркер не падает. Диагностика — 1,5–3 минуты (200–440 ресурсов). Экран — 360 / 768 / 1280 px, вкладки 54 px, консоль чистая. Тесты — 447 OK на PHP 8.3 OpenServer (+ `BotProtectionCheckTest` с фикстурами `tests/fixtures/bot-protection/`, `ResourceCheckTest`, `DiagnosticRunnerTest`, `ReportBuilderTest`). Тестовые диагностики удалены, `app.log` пуст.
**Что следующее:** Phase 2, таск 5 «Базовое SEO» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 2, таск 3 «Поиск ключевых страниц магазина» ✅. Перед началом — коммит тасков 1–2 (`1c9fb01`).
- `data/shop-patterns.php` — правила-данные по 10 платформам (WooCommerce, 1С-Битрикс, OpenCart, Joomla, PrestaShop, Magento, Shop-Script, Мегагрупп, InSales, Tilda) + общие: типовые пути корзины и оформления, шаблоны адресов и классов ссылок на категорию и товар, признаки страниц, признаки JS-каталога (Tilda), расширения файлов, которые не запрашиваются.
- `ShopCrawler.php` (без БД, сеть параметром): платформа — из `facts.stack.items` (`key`); категория — ссылки главной по убыванию похожести (адрес по шаблону платформы 3, общему 2, «Каталог» в тексте 2, класс меню 1, класс `product…` 1; кандидат — от 2), до первой подтверждённой; товар — адрес из формы (`manual`) или ссылки категории / главной; корзина и оформление — пути платформы → общие → ссылки «корзина» / «оформить заказ», засчитывается 2xx своего сайта, не главная и с признаком страницы в HTML (защита от «мягкого 404»). Тип страницы: `og:type=product` / одна разметка Product → товар, ≥ 3 ссылок на товары → категория, «в корзину» → товар только при похожем на товар адресе. Только свой хост, без файлов и дублей, кэш ответов, остановка, когда найдено всё. Причины «не найдена»: защита от ботов (403/429/503), JS-каталог, «нет подходящих ссылок», «проверено N — ни одна не похожа», «типовые адреса не подошли». Товар из формы с ошибкой → находка `availability` (4xx `medium`, 5xx / нет ответа `high`).
- `DiagnosticRunner.php`: шаг `crawl` между `stack` и `resources`; `buildStepContext()` + `product_url`; `runCrawlStep()` — страницы в `checked_pages` (`page_id` в факты и находки), причины в `summary_json.pages`, heartbeat. HTML страниц остаётся в фактах для таска 4. `HtmlParser.php` + `anchors` (адрес, текст до 200 символов, класс).
- Отчёт: `ReportBuilder::buildReportPages()` + карточка «Проверенные страницы» (главная → оформление; адрес, код, как найдена, время; «не найдена» с причиной; у старых диагностик — только найденные).
- Ужесточено после живой проверки citilink.ru (ложные «категория» `/services/digital/` и «товар» `/corporate/`): общий класс `item` / `card` больше не признак товара, «купить» убрано, «Магазины» / `shop` — не признак каталога; регрессионный тест.
- Проверено вживую: sudar.su — категория, товар, корзина и оформление (`/checkout/` — одна страница); cleansan.ru — категория, товар, корзина, оформление «не найдено» с причиной; citilink.ru — настоящий товар, корзина и оформление `/order/`, категория «не найдена»; 10–20 запросов на диагностику. Карточка — 360 / 768 / 1280 px. Тесты — 418 OK (+ `ShopCrawlerTest` с фикстурами `tests/fixtures/crawl/`, `HtmlParserTest`, `DiagnosticRunnerTest`, `ReportBuilderTest`). Тестовые диагностики удалены, `app.log` пуст.
- Окружение: правки через bash-heredoc / `python -c` превращали `\b` в регулярках в управляющий символ — поймано тестом, исправлено; такие правки теперь только скриптом из файла.
**Что следующее:** Phase 2, таск 4 «Проверки на всех страницах и защита от ботов» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 2, таск 2 «Адрес товара в форме» ✅.
- `UrlValidator.php`: поле `product_url` в `DIAGNOSTIC_FORM_FIELDS`; `validateProductUrl()` — пусто → null; формат через `normalizeSiteUrl()` (http/https, без логина и пароля, punycode, без фрагмента, длина); хост без `www.` должен совпадать с хостом сайта (поддомен — ошибка «Адрес товара должен быть с того же сайта: …»); путь `/` без параметров → «Укажите страницу товара, а не главную»; при ошибке в ссылке на сайт проверяется только формат. `validateDiagnosticInput()` возвращает `productUrl`.
- `createDiagnostic(…, ?string $productUrl)` пишет `diagnostics.product_url` (колонка уже была); `DiagnosticController::store()`, `HomeController` (`productMaxLength`).
- `pages/home.php`: поле «Адрес товара (необязательно)» между ссылкой и описанием, ошибка у поля, ввод сохраняется. Отчёт: `ReportBuilder` → `productUrl`, в «Сводке» строка «Адрес товара (указан вручную)», видна и без сводки доступности.
- Зачем поле: страница товара нужна проверкам на всех страницах (таск 4), скриншотам и ошибкам JS (Фаза 3), сценарию корзины (Фаза 5); ручной адрес — когда автопоиск (таск 3) не справится (каталог на JS, нестандартные адреса, защита от ботов) или клиент жалуется на конкретный товар.
- Проверено в браузере: чужой домен → ошибка у поля, ввод сохранён; `www.sudar.su/catalog/test-product/#reviews` → `https://www.sudar.su/catalog/test-product/` в БД и в сводке; пустое поле → `NULL`; 360 / 1280 px, поля 48 px. Тесты — 387 OK (+ `UrlValidatorTest`, `ReportBuilderTest`). Тестовые диагностики удалены через историю, `app.log` пуст.
**Что следующее:** Phase 2, таск 3 «Поиск ключевых страниц магазина» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 2, таск 1 «История диагностик» ✅. Перед этим план Фазы 2 перепроверен (`phase-init.md`): 8 тасков, история первой; источники уязвимостей — Wordfence + retire.js + NVD/OSV + свой список по Битриксу (WPScan отклонён: 25 запросов в день); базы обновляет `bin/update-databases.php` раз в неделю через Планировщик Windows (таск 6). Владелец получил токен Wordfence.
- `DiagnosticModel.php`: `searchDiagnostics()` / `countDiagnostics()` — `LIKE` по `host` и `url` (`%`, `_`, `\` экранируются), новые сверху, счётчики находок critical / high / всего подзапросами; `getDiagnosticStatus()`; `deleteDiagnostic()` — один `DELETE` с условием «не `queued` / `running`», связанные записи удаляет каскад FK.
- `HistoryService.php` (чистые функции): нормализация запроса (без схемы, `www.`, пути; кириллица → punycode), чтение `q` / `page`, пагинация (страница вне диапазона → ближайшая, пропуски `…`), адреса страниц, строки таблицы (дата, статус, счётчики, `canDelete`, кириллический домен через `idn_to_utf8`).
- `HistoryController`: `index` (20 строк на страницу, ошибка БД → лог + сообщение), `delete` (`requireCsrf()`, «не найдена» / «идёт проверка» / «удалена», возврат на ту же страницу поиска). Маршруты `GET /history`, `POST /diagnostics/{id}/delete`.
- `pages/history.php`: компоненты Tabler — поиск, таблица (на мобильных дата под сайтом, колонка находок скрыта), пагинация, пустые состояния, одно модальное окно удаления; `history.js` подставляет в него адрес формы, сайт и дату (`show.bs.modal`). Зоны нажатия от 48 px; на < 576 px пагинация «‹ текущая ›».
- Исправлена ошибка в документах: JS Tabler подключён в `header.php` (`tabler.min.js` с Bootstrap), модальные окна работают.
- Проверено в браузере на 46 временных записях: поиск (часть домена, полный адрес, кириллица, `%`, `_`), пагинация и границы, удаление со 2-й страницы поиска (находки удалены каскадом), отказы (выполняющаяся, без CSRF → 403, несуществующий и некорректный id), 360 / 768 / 1280 px, пустая история. Тесты — 370 OK (+ `HistoryServiceTest`). Тестовые записи удалены, `app.log` пуст.
- Окружение: сбой `Maximum call stack size … during compilation` (`public/index.php:3`) повторился на одном POST. Причина — автоопределение стека потока у PHP 8.3.6 ZTS (модуль Apache) в OpenServer; владелец выставил `zend.max_allowed_stack_size = -1` в php.ini OpenServer.
**Что следующее:** Phase 2, таск 2 «Адрес товара в форме» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 6 «Отчёт на экране» ✅. **Фаза 1 завершена.**
- `ReportBuilder.php` (чистые функции, без БД): `buildReport()` собирает готовые для View строки. Сводка из `summary_json`: ответ и итоговый адрес главной, время, редирект на https, www/без www, SSL (срок с предупреждением < 14 дней, издатель, домен, цепочка), 4 варианта адреса, цепочка редиректов, предупреждения о защите от ботов и обрезанном HTML; NULL или битый JSON дают «нет данных». Находки сгруппированы critical → info (пустые группы скрыты) со счётчиками, у каждой категория по-русски, `location`, адрес страницы по `page_id` (не повторяется, если совпадает с `location`), `details`, форматированный `evidence`. Стек по категориям (CMS → тема → плагины → сервер → PHP → CDN → JS → аналитика → другое), источники признака с подписями. «Не удалось проверить»: шаги `failed` / `skipped`, при `failed` диагностики ещё и `pending` / `running`. Подписи и классы бейджей вынесены в константы.
- Модели: `getFindingsByDiagnostic()` (порядок `FIELD(severity…)`), `getStackItemsByDiagnostic()`, `getCheckedPagesByDiagnostic()`; JSON `evidence` разбирается в модели.
- `DiagnosticController::show()`: отчёт строится для `done` и `failed`; при ошибке чтения отчёта пишет в лог и показывает «Не удалось загрузить отчёт», прогресс остаётся.
- `pages/diagnostic.php`: карточки «Сводка», «Стек», «Находки», «Не удалось проверить»; при `failed` под алертом ошибки пометка «Отчёт неполный». Компоненты `finding.php`, `stack-item.php`. Только компоненты Tabler; раскрытие на нативном `<details>` (`<summary>` ≥ 44 px); заголовки групп — `<li class="list-group-header d-block" role="heading">` внутри списка (вне `.list-group` пропадают отступы).
- `diagnostic-progress.js`: на `done` / `failed` вызывает `location.reload()`, отчёт рендерит сервер; `data-result-done` → `data-result-report`.
- Решения владельца: при `failed` выводится частичный отчёт; отчёт для `cancelled` не делался (статус в коде нигде не выставляется).
- Проверено вживую: sudar.su (6 находок, 12 элементов стека), cleansan.ru (13 / 5), citilink.ru (9 запасных шрифтов `low`, 3 элемента стека); example.com (тайм-аут SSL) — критичная находка и 2 пропущенных шага; прерванная диагностика (воркер остановлен, `heartbeat_at` состарен) — частичный отчёт и «Проблем не найдено». Автоперезагрузка по завершении работает. 360 / 768 / 1280 px без горизонтальной прокрутки, консоль чистая. Тесты — 345 OK (+ `ReportBuilderTest`). Тестовые записи удалены, `app.log` пуст.
- Окружение: браузер может держать старый `diagnostic-progress.js`, после обновления нужен Ctrl+F5. Разово (1 из ~30 запросов) Apache/PHP 8.3 отдал `Fatal error: Maximum call stack size … during compilation` в `public/index.php:3`, ещё до кода проекта. Похоже на баг PHP 8.3 на Windows (`zend.max_allowed_stack_size`), к проекту не относится.
**Что следующее:** Фаза 2 «Глубокая проверка и история»: перепроверить план промптом `.docs/prompts/phase-init.md` с учётом сделанного в Фазе 1, затем создать TASK.md для таска 1. Открытый вопрос к таску 3 — источник уязвимостей (WPScan / NVD / свой список).

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 5 «Битые ресурсы, PHP-ошибки, mixed content» ✅.
- `Checks/ResourceCheck.php` (без БД, сеть — параметром): ресурсы главной — стили, скрипты, иконки и preload из `<link>`, картинки (`srcset`, `data-src`, `<source>`, `poster`), фреймы, `url()` из inline CSS; внешние CSS скачиваются (GET), их `url()` и `@import` добавляются в очередь (относительно адреса CSS), `@import` внутри импортированного только проверяется. Тип — по источнику, внутри `@font-face` — шрифт. Адреса без `#` и пустого `?`, без дублей. HEAD → GET при любой неудаче; битый ресурс (4xx/5xx — `medium`, нет ответа — `low`) — отдельная находка `broken_resource` с типом, кодом, методом и `found_in`. Запасной формат шрифта при `woff2` в том же `@font-face` — `low` с пометкой. Нет главной → `skipped`; 403/503 или обрезанный HTML → `facts.limited`; `onProgress` каждые 10 ресурсов.
- `Checks/PhpErrorCheck.php`: `findPhpErrors()` — по тексту DOM (UTF-8, в т. ч. windows-1251), только по сигнатуре `<Тип>: … in <путь>.php on line N` / `:N` (Unix и Windows пути); путь — в `details`, фрагмент ±200 символов — в `evidence`; одинаковые (тип + файл + строка) — одна находка со счётчиком; Fatal/Parse — `critical`, Warning — `medium`, Notice/Deprecated — `low`.
- `Checks/MixedContentCheck.php`: http-ресурсы на https-странице — новая категория `mixed_content` (`FindingFactory.php`, `database.md`); скрипты/стили/фреймы/шрифты — `high`, картинки/иконки — `low`.
- `HtmlParser.php`: + `inline_styles`, `iframes`, ленивые картинки. `DiagnosticRunner::runResourcesStep()` (heartbeat через `touchDiagnosticHeartbeat()`) + `addPageErrorFindings()`; заглушка `STEP_NOT_IMPLEMENTED_MESSAGE` и её тест удалены — все шаги Фазы 1 настоящие.
- Отклонения: HEAD перепроверяется GET-ом при любой неудаче (Яндекс SmartCaptcha, VK, виджет Справочника отвечали на HEAD 404/418/488, на GET — 200); запасные форматы шрифтов понижены до `low` (на citilink.ru давали 12 «битых» шрифтов); `font.eot?#iefix` = `font.eot`.
- Проверено вживую: sudar.su — 269 ресурсов, 7 битых (шрифты slick без woff2, 2 картинки, тайм-аут CDN imshop); cleansan.ru — 260, 13 битых (иконки шаблона, в т. ч. `сourier-icon` с кириллической «с»); citilink.ru — 131, 9 запасных шрифтов `low`, воркер не падает. PHP-ошибок и mixed content на них нет. Тесты — 334 OK (+ `ResourceCheckTest`, `PhpErrorCheckTest`, `MixedContentCheckTest`, фикстуры в `tests/fixtures/resources/`). Тестовые записи удалены, `app.log` пуст.
- Окружение: в CLI-PHP `C:\php-8.4.14-nts-Win32-vs17-x64\php.ini` не включён `extension=intl` → `composer test` падает на 33 тестах `idn_to_ascii()`; с `php -d extension=intl vendor/bin/phpunit` всё проходит. php.ini вне проекта — не менялся.
**Что следующее:** Phase 1, таск 6 «Отчёт на экране» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 4 «Определение стека» ✅.
- `data/signatures.php` — правила-данные: платформы (WordPress, WooCommerce как плагин, 1С-Битрикс, OpenCart, Joomla, PrestaShop, Magento, Shop-Script, Мегагрупп (S3), InSales, Tilda) — заголовки, cookies, `generator`, пути, маркеры HTML, пути тем/плагинов, служебные файлы для версии; сервер / CDN по заголовкам; JS-библиотеки (jQuery, Migrate, UI, Bootstrap, Vue, React, Swiper, Slick, Fancybox, Lodash); счётчики (Метрика, GA, GTM).
- `HtmlParser.php`: `parseHtml()` на `DOMDocument` — мета (списком, несколько `generator`), скрипты, стили, `<link>`, картинки (с `srcset`), inline-скрипты, комментарии; абсолютные URL с `<base href>`, `data:`/`javascript:` отбрасываются; UTF-8 и windows-1251; битый HTML — пустые списки. Пригодится в таске 5.
- `StackDetector.php` (без сети и БД): у признаков вес (generator / явный заголовок 3, заголовок CMS 2, первый файл 2, путь / cookie / маркер 1), сумма ≥ 3 → `high`, 2 → `medium`, иначе `low`; один признак не считается дважды; ≤ 5 источников на элемент; длины под колонки. Темы и плагины — только для найденной CMS; WooCommerce сливается с плагином `woocommerce`. Версия темы/плагина из `?ver=` — только у WordPress (`asset_versions`), совпавшая с версией CMS сбрасывается.
- `Checks/StackCheck.php`: без главной в фактах → `skipped`; служебные файлы (`feed/`, `readme.html`, `joomla.xml`, `magento_version`) — только для найденной CMS без версии, до первого попадания; 403/503 или обрезанный HTML → `facts.limited`. Находок нет (устаревшие версии — Фаза 2).
- `StackModel::insertStackItem()`, таблица `stack_items` в `install.php`; `DiagnosticRunner::runStackStep()`. `resources` — пока заглушка. `DiagnosticRunnerTest` не запускает `stack` (сеть и БД).
- Отклонения: Мегагрупп добавлен после проверки cleansan.ru (без него CMS не находилась); `?ver=1.09` у файла шаблона Битрикса (sudar.su) давал ложную версию темы — отсюда флаг `asset_versions`.
- Проверено вживую: sudar.su — Битрикс, шаблон, 2 плагина, DDoS-Guard, 5 библиотек, Метрика; cleansan.ru — Мегагрупп, nginx, jQuery 2.2.4; citilink.ru — Qrator, Next.js, воркер не падает; wordpress.org/news — WordPress 7.2, Jetpack 16.3; несуществующий домен — `stack` `skipped`. `composer test` (PHP 8.3) — 286 тестов OK (+ `HtmlParserTest`, `StackDetectorTest`, `StackCheckTest`, фикстуры в `tests/fixtures/stack/`). Тестовые записи удалены, `app.log` пуст.
**Что следующее:** Phase 1, таск 5 «Битые ресурсы, PHP-ошибки, mixed content» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 3 «Доступность и SSL» ✅.
- `Checks/AvailabilityCheck.php` (без БД, сеть — параметрами): сначала адрес из диагностики, потом 4 варианта корня (http/https × www/без www, повторно не запрашиваются). Главная не открылась (нет ответа, тайм-аут, петля редиректов, ошибка сертификата) → `availability` `critical`, `stop`, варианты не запрашиваются (сертификат по https всё равно смотрится — объясняет причину). 4xx/5xx → `availability` `high`; 403/429/503 — только факт `possible_bot_protection`. Нет редиректа на https → `ssl` `medium`; https не открывается → `ssl` `high`; www и без www на разные хосты → `seo` `medium`; www без DNS — только факт. HTML и заголовки главной — в `facts.availability.home`, в сводку не попадают.
- `Checks/SslCheck.php`: `fetchPeerCertificate()` — `stream_socket_client` на проверенный публичный IP, SNI, без проверки цепочки (чтобы получить и просроченный), одна повторная попытка; `evaluateCertificate()` — срок, издатель, домены (SAN, иначе CN; `*.` на один уровень), находки: истёк `critical`, < 14 дней `medium`, чужой домен `high`, цепочка не доверена `high` (без дубля к истёкшему/чужому).
- Отклонение от плана: доверие к цепочке берётся из https-запроса curl к тому же хосту (curl 60 → не доверена), а не из второго TLS-соединения с `verify_peer` — оно на sudar.su и citilink.ru то проходило, то обрывалось («Unknown error») и давало ложную находку.
- `DiagnosticRunner::runAvailabilityStep()`: главная в `checked_pages` (`home`, `start`), `page_id` у находок по главной, сводка через `DiagnosticModel::updateDiagnosticSummary()` (`JSON_MERGE_PATCH` — ключи поздних фаз сохраняются). `stack`, `resources` — пока заглушки.
- Вне scope (согласовано в отчёте): в `DiagnosticRunnerTest` тест «шаги пропущены до реализации» больше не запускает `availability` (иначе сеть и БД).
- Решения владельца: тайм-аут = «сайт не отвечает» (`critical` + остановка, не шаг `failed`; формулировка DoD в phase-1.md исправлена); коды защиты от ботов — только в факты.
- Проверено вживую: sudar.su, cleansan.ru, citilink.ru — `done`, без находок, сертификаты доверены; несуществующий домен и зависший порт (`example.com:81`, обрыв за 30 с) — `critical`, остальные шаги `skipped`, прогресс 100; badssl: expired → `critical`, self-signed → не доверена, wrong.host → чужой домен, http.badssl → нет редиректа на https. `composer test` (PHP 8.3 OpenServer) — 232 теста OK (+ `AvailabilityCheckTest`, `SslCheckTest`). Тестовые записи удалены, `app.log` пуст.
**Что следующее:** Phase 1, таск 4 «Определение стека» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 2 «Каркас проверок: контекст шагов, HTTP-клиент, находки» ✅.
- `DiagnosticRunner.php`: контекст шагов `buildStepContext()` (`diagnostic` + `facts`); обработчик `handler(array $context)` возвращает `status`, `message`, `facts` (кладутся в `facts[<код шага>]`), `findings`, `stop`. `stop = true` → оставшиеся шаги `skipped` «Пропущено: шаг «…» остановил проверку», прогресс до 100. Упавший шаг не останавливает проверку, его факты и находки не принимаются. Находки записывает runner (`insertFinding()`), `executeSteps()` по-прежнему без БД. Заглушки со `sleep` и шаг `report` удалены; `availability`, `stack`, `resources` до тасков 3–5 — `skipped` «Проверка появится в следующей версии».
- `HttpClient.php` (функции): `httpRequest()` — ручные редиректы (≤ 10) с цепочкой `redirects`, проверка хоста через `validateSiteHost()` перед каждым запросом, curl идёт на уже проверенный IP (`CURLOPT_RESOLVE`), только http/https; тайм-ауты 10 / 30 с, предел ответа 5 МБ (`truncated`), SSL проверяется, ошибки — в `error` без исключений. `resolveRedirectUrl()` — относительный `Location` по RFC 3986. DNS и transport — параметрами (в тестах заглушки).
- `FindingFactory.php`: `makeFinding()` — серьёзность по категории (`FINDING_DEFAULT_SEVERITY`) с переопределением, отказ на неизвестные категорию/серьёзность; `buildFingerprint()` — sha1, адрес без `#фрагмента`, схема и хост в нижнем регистре.
- `FindingModel::insertFinding()`, `PageModel::insertCheckedPage()`; таблицы `checked_pages` и `findings` в `install.php`. `findings.ai_result_id` пока без FK — `ai_results` появится в фазе 4 (пометка в `database.md`).
- Проверено вживую: `sudar.su`, `cleansan.ru`, `citilink.ru` → 200 с цепочками редиректов (citilink в фазе 1 не блокирует); истёкший сертификат → ошибка curl 60; файл 100 МБ обрезан на 5 МБ (пик памяти 14 МБ); зависший порт → обрыв через 30 с; воркер проходит диагностику за 0,3 с, на экране 3 шага без «Сборки отчёта»; сценарий `stop` с записью находки и страницы в MySQL (JSON с кириллицей). `install.php` повторно — «уже есть». `composer test` — 195 тестов OK (+ `HttpClientTest`, `FindingFactoryTest`). Тестовые записи удалены, `app.log` пуст.
**Что следующее:** Phase 1, таск 3 «Доступность и SSL» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 1, таск 1 «Форма новой диагностики» ✅. План фазы 1 перепроверен и переписан (6 тасков; форма на главной, шага-заглушки `report` нет — решения владельца).
- `UrlValidator.php` (функции без БД): `normalizeSiteUrl()` — без схемы → `https://`, хост в нижний регистр, кириллица → punycode (`магазин.рф` → `xn--80aairftm.xn--p1ai`), без фрагмента, пустой путь → `/`; отказ на не-http(s), `user:pass@`, пробелы, > 2048. `validateSiteHost()` — отказ на `localhost` / `*.localhost`, одно слово без точки, нерезолвящийся домен, хотя бы один непубличный IP (`FILTER_FLAG_GLOBAL_RANGE`: частные, loopback, link-local, CGNAT, IPv6 ULA, IPv4-mapped). DNS — параметром (`resolveHostIps()`: A + AAAA без предупреждений PHP), в тестах заглушка. `extractBaseHost()` — `host` без `www.`. Описание: trim, пустое → `NULL`, > 5000 символов или битый UTF-8 — ошибка.
- `DiagnosticController::store()` (`POST /diagnostics`): CSRF → лимит **5 отправок за 60 с** (каждая отправка, успешная тоже; превышение → `logWarning()` + флеш, значения сохраняются) → проверка → `findLatestDiagnosticIdByHost()` → `createDiagnostic(…, previousId)` → воркер → экран прогресса. Временные `start()`, `example.com`, маршрут `/diagnostics/start` удалены.
- `HomeController` + `pages/home.php`: форма из компонентов Tabler (`type="text"` + `inputmode="url"`, чтобы браузер пропускал `shop.ru`), ошибки у полей, ввод сохраняется через сессию (как в настройках).
- Вне обычного scope (согласовано): `hitRateLimit($action, $decaySeconds = 60)` начинает счёт заново после окна — раньше через минуту после первой попытки лимит переставал срабатывать навсегда; тест в `RateLimitTest`.
- `APP_LOG_LEVEL=warning` в `.env` (владелец) и `.env.example`: при `error` предупреждения (лимит, в будущем — прочие `logWarning()`) в лог не попадали.
- Расхождение с DoD: `POST /diagnostics/start` отдаёт 405, а не 404 — путь совпадает с `GET /diagnostics/{id}`, роутер (Core) не трогали.
- Проверено в браузере: `192.168.1.1`, `localhost` → ошибка у поля, значения сохранены; `www.sudar.su`, `sudar.su`, `cleansan.ru`, `www.citilink.ru` → диагностики до `done`, `previous_id` проставляется; шестая отправка за минуту отклонена, через минуту форма снова работает; неверный CSRF → 403; 360 / 768 / 1280 px без горизонтальной прокрутки, кнопка и поле 48 px. Отказы по DoD с настоящим DNS проверены из CLI (`shop.ru` и `магазин.рф` реально не резолвятся). `composer test` — 137 тестов OK (+ `UrlValidatorTest`). Тестовые записи, файлы лимита и скриншоты удалены.
**Что следующее:** Phase 1, таск 2 «Каркас проверок: контекст шагов, HTTP-клиент, находки» — создать TASK.md.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 6 «Экран прогресса диагностики» ✅. **Фаза 0 завершена.**
- Маршруты: `GET /diagnostics/{id}`, `GET /diagnostics/{id}/status` (JSON), временный `POST /diagnostics/start`.
- `DiagnosticStatusService.php`: `parseDiagnosticId()` (`^[1-9][0-9]{0,9}$`), `buildDiagnosticStatus()` / `buildStepStatus()` — подписи и бейджи Tabler в одном месте, JS берёт их из JSON; пока воркер не создал шаги — плановые шаги со статусом «Ожидает».
- `DiagnosticController.php`: `show` / `status` сначала вызывают `refreshStaleDiagnostic()`; неверный или несуществующий id → 404 (страница `empty` / JSON `not_found`), ошибка БД → лог + 500. `start` (временный, до формы фазы 1): `https://example.com` → `launchWorker()`; сбой запуска → диагностика `failed` с причиной, лог, флеш.
- `pages/diagnostic.php` (вместо `diagnostics/show.php` из плана): состояния «в очереди», «идёт», «готово» (карточка под отчёт), «ошибка», «не найдена»; нативный `<progress class="progress">` — без inline-стилей.
- `diagnostic-progress.js`: опрос раз в 2 с через `setTimeout` после ответа, `textContent`, остановка на `done` / `failed` / 404, предупреждение при обрыве связи.
- `home.php`: временная кнопка «Тестовый запуск (заглушка)».
- Вне scope по просьбе владельца: `requireCsrf()` отвечает **403** вместо 419 (Apache в OpenServer подменял 419 на 500); `.phpunit.result.cache` в `.gitignore`.
- Проверено в браузере: прогресс 25 → 100 без перезагрузки, после `done` запросов нет, консоль чистая; воркер доходит до `done` после закрытия вкладки; «в очереди», «прервана» (heartbeat 11 мин), неверный `PHP_CLI_PATH`, 404 на странице и в JSON, CSRF → 403; 360 / 768 / 1280 px. `composer test` — 84 теста OK (+ `DiagnosticStatusServiceTest`). Тестовые записи, лог и скриншоты удалены.
**Что следующее:** Фаза 1 «Экспресс-диагностика» — перепроверить план `phase-1.md` промптом `.docs/prompts/phase-init.md` с учётом сделанного, затем создать TASK.md для таска 1. Временную кнопку `start` и заглушку `example.com` убрать вместе с формой фазы 1.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 5 «Фоновое выполнение диагностики» ✅.
- `database/install.php`: таблицы `diagnostics` и `diagnostic_steps` по `database.md` (FK, индексы), установка идемпотентна.
- `DiagnosticModel.php` (функции): создание, атомарный захват `queued → running` (`claimDiagnostic()`), шаги, прогресс + heartbeat, завершение, `failStaleDiagnostic()` — транзакция; время везде через `NOW()` в SQL.
- `DiagnosticRunner.php`: 4 шага-заглушки по 2 с; `executeSteps()` без БД — try/catch на шаг, `done / skipped / failed`, обрезка сообщения до 1000; диагностика с упавшим шагом → `done`; `refreshStaleDiagnostic()` (10 мин) — для экрана таска 6.
- `JobLauncher.php`: `start "" /B "<php>" "<worker>" <id> > NUL 2>&1`; пути с `"`, `%`, переводом строки отклоняются; неверный `PHP_CLI_PATH` → исключение.
- `bin/worker.php`: только CLI, строгая проверка id (код 2), не в очереди → код 1 без изменений в БД, ошибка воркера → лог + `failed`.
- Проверено CLI-скриптами: запуск за 0,012 с, прогресс до 100, повтор для `running`/`done`, неверные id, падение шага (скрипт, повторяющий поток `runDiagnostic()`), зависание `running` и `queued`; `composer test` — 62 теста OK (+ `DiagnosticRunnerTest`, `JobLauncherTest`). Тестовые записи и тестовый `app.log` удалены.
- Перенесено в таск 6: проверка запуска из браузера и работы воркера после закрытия вкладки (добавлено в DoD таска 6 в `phase-0.md`).
**Что следующее:** Phase 0, таск 6 «Экран прогресса диагностики» — создать TASK.md через `.docs/prompts/task-init.md`. Мелочь на потом: `.phpunit.result.cache` в `.gitignore`.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 4 «Страница настроек» ✅.
- `/settings`: модели RouterAI для трёх ИИ-задач (`model_problem`, `model_visual`, `model_summary`) сохраняются в `settings`; ключи `.env` (`ROUTERAI_API_KEY`, `PAGESPEED_API_KEY`, `WPSCAN_API_KEY`) показаны маской `sk-…a1b2` или «не задан», `ROUTERAI_BASE_URL` — целиком; полей ввода ключей нет.
- `SettingsModel.php` (функции): `getAllSettings()`, `getSetting()`, `saveSettings()` — `INSERT … ON DUPLICATE KEY UPDATE` одной транзакцией с откатом.
- `SettingsService.php`: проверка названия модели (не пусто, ≤ 150, `[A-Za-z0-9._:/-]`, модификатор `D`), `validateSettingsInput()`, `maskSecret()`, `getEnvKeysOverview()`.
- `SettingsController.php`: `requireCsrf()`, ошибки полей и ввод — в сессии на один показ, ошибка БД → `logException()` + флеш.
- Шаблон `src/Views/pages/settings.php` (а не `settings/index.php` из плана — по аналогии с `pages/home.php`); `form-control-lg` / `btn-lg` для зон нажатия ≥ 44 px без своих стилей.
- Проверено: валидация (пусто / 151 символ / кириллица), сохранение и перезагрузка, CSRF → 419, откат транзакции, 360 / 768 / 1280 px, полного ключа нет в HTML, `app.log` пуст; `composer test` — 40 тестов OK. Тестовые записи из `settings` удалены.
**Что следующее:** Phase 0, таск 5 «Фоновое выполнение диагностики» — создать TASK.md через `.docs/prompts/task-init.md`. Мелочь на потом: `.phpunit.result.cache` в `.gitignore`.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 3 «БД: установка таблиц» ✅.
- `database/install.php` переписан: запуск только из CLI (`PHP_SAPI`), таблица `settings` по `database.md`, таблицы `users` нет (входа нет).
- Идемпотентен: `CREATE TABLE IF NOT EXISTS`, по каждой таблице «создана / уже есть»; коды выхода `EXIT_OK` / `EXIT_ERROR`.
- Ошибка БД: общее сообщение в STDERR без DSN и пароля, подробности — в `app.log` через `logException()`.
- Проверено: структура `settings` совпадает со схемой, повторный запуск сохраняет данные, неверный `DB_NAME` → код 1 и запись в логе (пароль скрыт), запуск через php-cgi ничего не делает, `composer test` — 25 тестов OK.
- Замечание: в трассировке лога виден DSN без пароля (`mysql:host=…`) — поведение `logException()` в `src/Core/`, не менялось.
**Что следующее:** Phase 0, таск 4 «Страница настроек» — создать TASK.md через `.docs/prompts/task-init.md`. Мелочь на потом: `.phpunit.result.cache` в `.gitignore`.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 2 «Подключить Tabler и макет приложения» ✅.
- Решение владельца: входа нет (ни пароля, ни страницы входа, ни `users`). Обновлены `prd.md`, `database.md`, `_status.md`, `phase-0.md` (таск 3 → «БД: установка таблиц», из таска 4 убрана смена пароля), `phase-3.md`.
- Макет Tabler: `src/Views/admin/layout/header.php`, `footer.php`; меню: Новая диагностика `/`, История `/history`, Настройки `/settings`; на мобильном — бургер.
- Флеш-сообщения: `src/Views/components/flash.php` (успех / ошибка, экранирование, закрытие), тест `tests/Unit/FlashComponentTest.php`.
- `public/assets/css/admin-theme.css`: зона нажатия 44 px для бургера и пунктов меню (в Tabler 32 / 40 px).
- `home.php` и `HomeController`: заглушка «Новая диагностика» в макете.
- `.htaccess` не менялся: OpenServer слушает только loopback `127.127.126.54`, из сети сайт недоступен.
- Проверено: 360 / 768 / 1280 px, консоль без ошибок, `composer test` — 25 тестов OK.
**Что следующее:** Phase 0, таск 3 «БД: установка таблиц» — создать TASK.md через `.docs/prompts/task-init.md`. Мелочь на потом: `.phpunit.result.cache` в `.gitignore`.

**Дата:** 15.11.2026
**Что сделано:** Phase 0, таск 1 «Окружение и очистка шаблона» ✅.
- `.env.example`: убран SMTP; добавлены `APP_NAME`, `APP_URL`, `PHP_CLI_PATH`, `ROUTERAI_BASE_URL`, `ROUTERAI_API_KEY`, `PAGESPEED_API_KEY`, `WPSCAN_API_KEY`, закомментированные `LOG_DIR` / `LOG_FILE`.
- `.env`: `APP_URL` → `https://service-diagnostic-site.local`, `API_KEY_ROUTERAI` переименован в `ROUTERAI_API_KEY`, добавлены новые переменные.
- `config/config.php`: константы `APP_NAME`, `STORAGE_PATH`, `SCREENSHOTS_PATH`, `PDF_PATH`, `PHP_CLI_PATH`, `ROUTERAI_BASE_URL`.
- `config/routes.php`: оставлен только `GET /`; `/login`, `/logout`, `/register` убраны до таска 3.
- Созданы `HomeController` и заглушка `src/Views/pages/home.php`.
- Созданы `storage/screenshots/`, `storage/pdf/` (содержимое в `.gitignore`).
- Вне Scope: `src/Core/Database.php` — `env('DB_PASS', '')`, т. к. пароль root в OpenServer пустой.
- Проверено: `/` → 200, `/login`, `/register` → 404, подключение к БД работает, `composer test` — 21 тест OK, PHP CLI 8.3.6.
**Что следующее:** Phase 0, таск 2 «Подключить Tabler и макет приложения» — создать TASK.md через `.docs/prompts/task-init.md`. Мелочь на потом: добавить `.phpunit.result.cache` в `.gitignore`.

**Дата:** 15.11.2026
**Что сделано:** спланированы фазы 0–5 по `prd.md` (`.docs/phases/phase-0.md` … `phase-5.md`, `_status.md`), заполнена схема БД (`.docs/database.md`).
**Что следующее:** закрыть открытые вопросы из `_status.md` (образцы экранов / Tabler), создать TASK.md для Фазы 0, таск 1.
