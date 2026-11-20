# Dev Log

Журнал рабочих сессий. Свежие записи — сверху, сразу под этим блоком.

---

## Формат записи

**Дата:** ДД.ММ.ГГГГ
**Что сделано:** ...
**Что следующее:** ...

---

**Дата:** 20.11.2026
**Что сделано:** выполнена реальная E2E-диагностика `#170` сайта `https://nakode.ru/` через CLI-воркер `bin/worker.php 170` и локальный OpenServer (`https://service-diagnostic-site.local`) для проверки правок ревью (`review-paln.md`, волны 1–5) в боевых условиях.
- **Прогон воркера (`bin/worker.php 170`):** завершился с кодом `0` (`14:30:30` → `14:38:52`, `status = done`, `progress = 100`). 14 шагов завершены в `done`, шаг `checkout` — штатно `skipped` (`страница товара не найдена — сценарий заказа не от чего начинать`).
- **Собранные факты и находки:** стек — 7 элементов (`WordPress 7.1.5`, тема `myblogtheme`, `nginx`, `Bootstrap`, `jQuery 3.7.1`, `jQuery Migrate 3.4.1`, `Яндекс Метрика`); проверено страниц: 2 (`https://nakode.ru/` и статья `https://nakode.ru/kak-obnovlyat-woocommerce-i-ne-slomat-oformlenie-zakaza/`, распознанная эвристикой как `checkout`), ресурсов: 67 (битых 0), открытых служебных файлов: 0 из 21; SEO: `robots.txt` + индекс sitemap из 3 файлов (121 URL); Chrome: 2 из 2 страниц (5 из 6 загрузок, 0 ошибок JS, 0 упавших запросов, 5 скриншотов); PageSpeed: Mobile `99`, Desktop `100`; ИИ (`qwen/qwen4-max`): 5 записей `ai_results` (`status = ok`); всего 8 находок (`critical`: 1, `high`: 1, `medium`: 3, `low`: 3; `max_details_bytes = 1174`).
- **Веб-отчёты и PDF:** `GET /diagnostics/170` (HTTP 200, 151 959 байт), `GET /diagnostics/170/client` (HTTP 200, 15 563 байт), `GET /diagnostics/170/pdf?version=full` (HTTP 200, `application/pdf`, 6 731 846 байт, `%PDF-`), `GET /diagnostics/170/pdf?version=client` (HTTP 200, `application/pdf`, 63 122 байт, `%PDF-`).
- **Чистота логов и временных файлов:** размер `storage/logs/app.log` не изменился (`11 038` байт — 0 новых ошибок и предупреждений), в `storage/temp/` не осталось профилей `chrome-*`, в `storage/pdf/` — только `.gitkeep`. Подтверждено на практике ограничение не-магазинного сайта: краулер принял статью со словами о WooCommerce/оформлении за страницу `checkout`, а ИИ-анализ счёл отсутствие магазина критической гипотезой.
**Что следующее:** 1) при необходимости проверить шаг `checkout` на 2–3 реальных интернет-магазинах; 2) при желании доработать промпты/эвристики под не-магазинные сайты или выполнить оставшиеся за рамками плана задачи из `review-paln.md`.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 2 плана ревью (`review-paln.md`, Волна 5, часть 3–4) — защита от отправки реального заказа в сценарии оформления (`default-deny` для изменяющих запросов, проверка тела `POST` и `GET`-заказов, анализ формы кнопок, перехват дочерних целей без `Fetch.disable`). **Волна 5 завершена (пункты 1, 2).**
- `data/checkout-selectors.php`: из `generic.add_to_cart_texts` убран паттерн `buy(?:\s+now)?`, в `forbidden_texts` добавлен `\bbuy\s+now\b`, в `forbidden_attrs` добавлены безопасные шаблоны (`buy[-_]?now`, `(?:make|create|new|place|submit)[-_]?order`, `order[-_]?(?:form|btn|button|now|modal|popup|make|create)`, `zakaz`, `oformlen`, `payment`, без блокировки `order-1`), расширены `requests.order` и `requests.add_to_cart` (`cart[/.]add` для OpenCart 4, `cart[-_]?add`, `basket[-_]?add`), добавлены `requests.add_to_cart_body` и `requests.order_body`.
- `src/Services/CheckoutScenario.php` и `src/Services/DiagnosticRunner.php`:
  - `CHECKOUT_PROBE_SCRIPT` и `normalizeCheckoutProbe()` собирают `data-url`, `data-href`, `data-target` и `form_action` (`formaction` кнопки или `action` ближайшей `<form>`); `isCheckoutFormActionForbidden()` и `isForbiddenCheckoutButton()` блокируют кнопки с опасным `form_action` и «купить» со ссылкой/атрибутами на заказ;
  - `extractCheckoutRequestPostData()` декодирует `postData` и `postDataEntries` (с ограничением `CHECKOUT_POST_DATA_MAX_BYTES = 16384`); `isStaticCheckoutAssetGet()` и `decideCheckoutRequest()` реализуют `default-deny` для изменяющих запросов (до оформления пропускаются только запросы добавления в корзину без признаков заказа в теле, на стадии оформления блокируются все) и блокируют «заказные» GET-запросы (кроме статических `.css`/шрифтов/картинок/`.js` и самих страниц оформления, не относящихся к быстрому заказу); `recordCheckoutRequest()` учитывает и заблокированные не-изменяющие запросы;
  - добавлены `buildCheckoutBrowserOptions()` (передаётся в `launchBrowser()` в `runCheckoutStep()`), проверка стартового адреса и ранняя отмена `navigateBrowserPage()` по `$run['ssrf_blocked_main']` (исправлена передача состояния по ссылке `&` в замыканиях `$shouldAbort` в `CheckoutScenario.php` и `BrowserService.php`); `attachCheckoutListeners()` переведён на общий `attachBrowserRequestInterception()` с проверкой `isBrowserRequestAllowed()` и анализом `postData`; из `runCheckoutScenario()` убран `Fetch.disable` до закрытия вкладки.
- Тесты: в `tests/Unit/CheckoutScenarioTest.php` обновлены 3 устаревших утверждения под новую политику и добавлены проверки `decideCheckoutRequest()` (GET/POST/GraphQL/RPC/стадия `checkout`), `extractCheckoutRequestPostData()`, `recordCheckoutRequest()`, анализа `form_action`/`data-*` кнопок и 3 `MockSocket`-теста на `buildCheckoutBrowserOptions()`, `attachCheckoutListeners()` (включая дочерний `iframe`) и `runCheckoutScenario()` (SSRF + отсутствие `Fetch.disable`).
- Проверки: `php -l` на всех 8 изменённых и новых файлах Волны 5 — без ошибок; целевые тесты (`BrowserServiceTest|CheckoutScenarioTest`) — 54 теста, 288 assertions за 0.169 с, (`CheckoutScenarioTest|CheckoutCheckTest`) — 56 тестов, 343 assertions; полный прогон PHPUnit на PHP 8.3 OpenServer — 1098 тестов, 3984 assertions (OK, пик 36.00 MB); локальный E2E на реальном Chrome 159 (`data:`-страница под `attachCheckoutListeners` + `buildCheckoutBrowserOptions()`) заблокировал `POST /order/create`, `POST /wp-admin/admin-ajax.php` (`action=create_order`) и `GET http://127.0.0.1:9/ssrf` (`checkout_sent=0`, `blocked=3`, `passed=0`, `errors=0`); в `storage/logs/app.log` добавилась только ожидаемая запись WARNING от SSRF-запроса (всего 5 ожидаемых WARNING за Волну 5), `storage/temp/` без оставшихся `chrome-*` профилей. Ручной E2E на 2–3 живых интернет-магазинах не запускался (внешнее действие — требует отдельного разрешения владельца).
**Что следующее:** 1) согласование с владельцем и ручной E2E-прогон на реальных магазинах/тестовых URL (`review-paln.md`, «Общая проверка после всех пунктов»); 2) Волна 6 / оставшиеся за рамками плана задачи по решению владельца (локальный фильтрующий прокси против DNS-rebinding в Chrome, проверка кода запуска в `JobLauncher::launchWorker()`).

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 1 плана ревью (`review-paln.md`, Волна 5, часть 1–2) — защита от SSRF через headless Chrome (перехват CDP, защита PDF-режима, дочерние цели и всплывающие окна).
- `src/Services/BrowserRequestGuard.php`: добавлены чистые функции проверки адреса для Chrome (`isBrowserRequestAllowed()` поверх `normalizeHostName()` / `validateSiteHost()` с блокировкой локальных/приватных/служебных адресов, нестандартных схем, backslash/userinfo-обходов и fail-closed при ошибке резолвера), строгий режим печати PDF (`isBrowserPdfRequestAllowed()` — только `file:///` без UNC и `..`, `data:` и `about:blank|srcdoc`), TTL-кэш резолвера (`createCachedBrowserResolver()`) и очистка адресов для лога (`sanitizeBrowserLogUrl()`).
- `src/Services/BrowserService.php`: в `buildBrowserOptions()` исключён `--disable-popup-blocking` и добавлен `--block-new-web-contents`; добавлены `buildBrowserPdfLaunchOptions()` (`disableJavascript => true`, `ignoreCertificateErrors => false`) и общий CDP-перехватчик `attachBrowserRequestInterception()` (`Network.setBypassServiceWorker`, `Fetch.enable`, `Target.setAutoAttach` с перехватом дочерних `iframe`/воркеров, закрытием сторонних `page`-целей через `Target.closeTarget`, fail-closed при ошибке и без `Fetch.disable` до закрытия вкладки). В `navigateBrowserPage()` добавлена ранняя остановка по `$shouldAbort`; `openBrowserPage()` проверяет стартовый URL до создания вкладки, блокирует SSRF-редиректы и не снимает скриншот при блокировке главного документа; `printBrowserHtmlToPdf()` проверяет `file:///` через `isBrowserPdfRequestAllowed()` и печатает под строгим перехватом без JS и без сети.
- Тесты: создан `tests/Unit/BrowserRequestGuardTest.php` (54 теста, 62 assertions — URL-правила браузера и PDF, TTL-кэш резолвера, fail-closed); расширен `tests/Unit/BrowserServiceTest.php` (`buildBrowserOptions`, `buildBrowserPdfLaunchOptions`, отказ от небезопасных PDF-URI до запуска Chrome и 3 `MockSocket`-теста на `attachBrowserRequestInterception()` / `openBrowserPage()`).
- Проверки: `php -l` на `BrowserRequestGuard.php`, `BrowserService.php` и их тестах — без ошибок; целевые тесты (`BrowserRequestGuardTest|BrowserServiceTest|PdfServiceTest`) — 83 теста, 184 assertions (OK); локальный E2E на реальном Chrome 159 (`C:\Program Files\Google\Chrome\Application\chrome.exe`): прямой запрос `http://127.0.0.1/`, JS-редирект с `data:` на `http://127.0.0.1:9/ssrf` и `iframe` на `http://127.0.0.1:9/iframe-ssrf` заблокированы (без скриншота), `printBrowserHtmlToPdf()` с внешним `<img>` и `<script>` собрал валидный `%PDF-` (15 809 байт), в `storage/logs/app.log` зафиксированы только 4 ожидаемые записи WARNING о блокировке SSRF без PHP errors/warnings и секретов, профили в `storage/temp/` удалены.
**Что следующее:** пункт 2 плана ревью (`data/checkout-selectors.php`, `CheckoutScenario.php`, `DiagnosticRunner.php` — default-deny для изменяющих запросов и защита от отправки реального заказа).

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 11 плана ревью (`review-paln.md`, Волна 4) — heartbeat по времени, оптимистичная блокировка БД, защитные лимиты и обработка фатальных ошибок воркера. **Волна 4 завершена (пункт 11).**
- `src/Services/DiagnosticNoLongerRunningException.php`: добавлено исключение `DiagnosticNoLongerRunningException extends Exception` (не `RuntimeException`, чтобы локальные `catch (RuntimeException)` в проверках и ИИ-анализе его не перехватывали).
- `src/Core/Database.php`: добавлен флаг `PDO::MYSQL_ATTR_FOUND_ROWS => true` в `getPdo()`, чтобы повторное обновление `heartbeat_at = NOW()` в ту же секунду возвращало `rowCount() === 1` для живой `running`-диагностики.
- `src/Models/DiagnosticModel.php` и `src/Controllers/DiagnosticController.php`: функции воркера (`resetAiSteps`, `markStepRunning`, `finishStep`, `updateDiagnosticProgress`, `updateDiagnosticSummary`, `touchDiagnosticHeartbeat`, `finishDiagnostic`) получили опциональный `?PDO $pdo = null`, ограничение `status = 'running'` и возврат `bool`; `resetAiSteps()` проверяет `status = 'running'` первым в транзакции и при необходимости откатывается без удаления данных, использует переносимый SQL (`SELECT` + `UPDATE`/`INSERT`); `finishDiagnostic()` использует `CASE WHEN` и `bindValue(..., PDO::PARAM_INT)`. Добавлены `failQueuedDiagnostic()`, `failRunningSteps()`, `failRunningDiagnostic()`. В `DiagnosticController::markLaunchFailed()` вызов `finishDiagnostic()` заменён на `failQueuedDiagnostic()`.
- `src/Services/DiagnosticRunner.php`: добавлены `HEARTBEAT_MIN_INTERVAL_SECONDS = 30`, `assertDiagnosticRunning()`, `withHeartbeat()`, `isFatalWorkerError()`, `buildWorkerFatalMessages()` и `handleWorkerFatalError()`. Обёртка `withHeartbeat()` и проверки `assertDiagnosticRunning()` применены ко всем сетевым и ИИ-шагам (`availability`, `stack`, `outdated`, `vulnerabilities`, `crawl`, `resources`, `seo`, `exposed_files`, `browser`, `checkout`, `pagespeed`, `ai_problem`, `ai_visual`, `ai_summary`, `ai_client`). `executeSteps()`, `finalizeStepPersistence()`, `executeStepsWithDb()`, `runDiagnostic()` и `runAiRerun()` пробрасывают `DiagnosticNoLongerRunningException` и принимают `$onClaimed`.
- `bin/worker.php`: добавлен резерв `WORKER_SHUTDOWN_MEMORY_RESERVE_BYTES = 131072` (128 КБ), `register_shutdown_function` с вызовом `handleWorkerFatalError()`, тихое завершение `EXIT_OK` при `DiagnosticNoLongerRunningException` и перевод активной диагностики в `failed` через `failRunningDiagnostic()`.
- `src/Services/Checks/SeoCheck.php`, `src/Services/StackDetector.php`, `src/Services/Checks/OutdatedCheck.php`, `src/Services/Checks/VulnerabilityCheck.php`: зафиксированы согласованные с владельцем лимиты (`SEO_MAX_SITEMAP_CANDIDATES = 5` с O(1) дедупликацией в `parseRobotsTxt()` и `array_slice` в `checkSitemap()`; `STACK_MAX_PLUGINS_PER_CMS = 100` и `STACK_MAX_THEMES_PER_CMS = 20` без отсечения плагинов-платформ и донакопления версий; `WP_PLUGIN_LOOKUP_MAX_PER_DIAGNOSTIC = 30` только на онлайн-запросы при промахе кэша с отражением пропуска в `facts['plugin_lookups_skipped']` и сообщении шага), а также проброс `DiagnosticNoLongerRunningException` в `OutdatedCheck.php` и `VulnerabilityCheck.php`.
- `.docs/phases/_status.md`: зафиксированы решения владельца от 20.11.2026 по лимитам в `SeoCheck.php`, `OutdatedCheck.php` и `StackDetector.php`.
- Тесты: создан `tests/Unit/DiagnosticModelTest.php` (7 тестов оптимистичной блокировки и транзакций на SQLite `:memory:`); дополнены `DiagnosticRunnerTest.php` (6 тестов), `SeoCheckTest.php` (2 теста), `StackDetectorTest.php` (1 тест), `OutdatedCheckTest.php` (2 теста), `VulnerabilityCheckTest.php` (1 тест).
- Проверки: `php -l` на всех 16 файлах — без ошибок; целевые тесты (`DiagnosticModelTest|DiagnosticRunnerTest|SeoCheckTest|StackDetectorTest|OutdatedCheckTest|VulnerabilityCheckTest`) — 156 тестов, 896 assertions (OK); полный прогон PHPUnit на PHP 8.3 OpenServer — 1035 тестов, 3809 assertions (OK, пик 36.00 MB); `bin/worker.php 0` и `bin/worker.php 1 --bad` вернули код `2`; изолированная CLI-проверка OOM под `-d memory_limit=16M` с резервом 128 КБ подтвердила срабатывание `register_shutdown_function` и `handleWorkerFatalError()` (`OOM_RESULT=true`). В `storage/logs/app.log` новых записей нет (размер остался 10 160 байт). Живая диагностика и MySQL-воркер не запускались.
**Что следующее:** Волна 5 — пункты 1 и 2 (`UrlValidator` / `BrowserService` / `CheckoutScenario` — SSRF через headless Chrome и защита от отправки реального заказа).

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 9 плана ревью (`review-paln.md`, Волна 3) — устранено накопление тел ответов в памяти и введён лимит 500 ресурсов за диагностику. **Волна 3 завершена (пункты 14, 13, 9).**
- `src/Services/Checks/ResourceCheck.php`: добавлены константы `RESOURCE_EXISTENCE_PROBE_BYTES` (`1`), `RESOURCE_RANGE_NOT_SATISFIABLE_STATUS` (`416`) и `RESOURCE_MAX_PER_DIAGNOSTIC` (`500`, по согласованию с владельцем) и хелпер `enqueueResourceEntry()`. При отказе `HEAD` проверка `checkResource()` запрашивает 1 байт через `Range` (при ответе `416` на пустой файл повторяет `GET` без `Range`), тела неразбираемых ресурсов (включая импортированные стили глубже `RESOURCE_CSS_MAX_DEPTH`) не запрашиваются и не сохраняются. В `runResourceCheck()` тело CSS извлекается в `$cssBody` и освобождается сразу после `extractCssResources()`, а в `$entries` остаются только метаданные; при превышении лимита 500 уникальных ресурсов новые записи не создаются (с сохранением привязки уже добавленных к новым страницам), `facts['limited']` выставляется в `true`, возвращается `facts['skipped_by_limit']`, а `buildResourcesStepMessage()` отражает пропуск.
- `src/Services/DiagnosticRunner.php`: `runResourcesStep()` передаёт `$maxBytes` в `httpRequest()`; `addPageErrorFindings()` передаёт `skipped_by_limit` в `buildResourcesStepMessage()`.
- `src/Services/ShopCrawler.php`: добавлен `findSelectedCrawlPage()`, в `fetchCrawlPage()` кэш `$crawl['responses']` хранит только метаданные (`final_url`, `status`, `time_ms`, `error`, `truncated`, `type`) без `body` и `headers`; уже классифицированные нецелевые страницы пропускаются при поиске товара, а полные тела сохраняются только в `$pages` для выбранных страниц.
- `.docs/phases/_status.md`: зафиксировано решение владельца от 20.11.2026 о лимите 500 уникальных ресурсов в `ResourceCheck.php`.
- Тесты: в `ResourceCheckTest.php` добавлены 4 теста (300 ресурсов × 5 МБ с `HEAD 405`, 300 стилей × 5 МБ, проверка `maxBytes` / `416` / глубины CSS и проверка лимита ресурсов с дедупликацией по страницам); в `ShopCrawlerTest.php` добавлены 2 теста (60 отброшенных кандидатов × 5 МБ и общая страница корзины/оформления с одним запросом).
- Целевые тесты (`ResourceCheckTest|ShopCrawlerTest|DiagnosticRunnerTest|MixedContentCheckTest`): 103 теста, 373 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 1016 тестов, 3662 assertions (пик памяти PHPUnit — 34 МБ). Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт). Живая диагностика сайтов и БД не запускались.
**Что следующее:** Волна 4 — пункт 11 (`DiagnosticRunner.php`, `DiagnosticModel.php`, `SeoCheck.php`, `OutdatedCheck.php`, `StackDetector.php`, `bin/worker.php` — heartbeat, оптимистичная блокировка, лимиты и обработчик фатальных ошибок) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 13 плана ревью (`review-paln.md`, Волна 3) — добавлен rate limit на экспорт PDF и освобождение блокировки сессии на долгих и read-only запросах.
- `src/Core/functions.php`: добавлен хелпер `closeSessionWrite()`, закрывающий активную сессию (`session_write_close()`).
- `src/Controllers/ExportController.php`: добавлены константы `RATE_LIMIT_ACTION` (`pdf_export`), `RATE_LIMIT_MAX_ATTEMPTS` (`5`), `RATE_LIMIT_WINDOW_SECONDS` (`60`) и метод `takePdfRateLimitAttempt()` на базе атомарного `takeRateLimitAttempt()` с записью в журнал при превышении; в `download()` после проверки прав/статуса и списания попытки лимита вызывается `closeSessionWrite()` до рендера и запуска Chrome.
- `src/Controllers/SettingsController.php`: в `saveBrowser()` перед `checkChrome()` вызывается `closeSessionWrite()`; в `save()` при `action=check` запись флеша о сохранении и предупреждения перенесена внутрь `checkConnection()` после выполнения сетевых проверок при закрытой сессии, чтобы параллельные вкладки не перехватывали флеш-сообщения.
- `src/Controllers/DiagnosticController.php` (`status()`) и `src/Controllers/ScreenshotController.php` (`send()`): в начале методов вызывается `closeSessionWrite()`, чтобы опрос статуса и загрузка скриншотов не выстраивались в очередь за блокировкой файла сессии.
- Тесты: в `ExportControllerTest.php` добавлена проверка отклонения 6-й попытки экспорта PDF в окне 60 с; в `FunctionsTest.php` добавлен тест освобождения блокировки через `closeSessionWrite()` и автоматического переоткрытия сессии при `setFlash()` с сохранением данных.
- Целевые тесты (`ExportControllerTest|RateLimitTest|FunctionsTest`): 21 тест, 46 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 1010 тестов, 3633 assertions. Проверены маршруты OpenServer `/`, `/settings`, `/history` (HTTP 200). Живой экспорт PDF через Chrome не запускался. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** пункт 9 плана ревью (`ResourceCheck.php`, `DiagnosticRunner.php`, `ShopCrawler.php` — исключение хранения тел ответов в памяти и лимит 500 ресурсов).

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 14 плана ревью (`review-paln.md`, Волна 3) — добавлена защита периметра `Require local` и защита от утечки секретов в трассировках и логах.
- `public/.htaccess`: добавлен блок `<IfModule mod_authz_core.c> Require local </IfModule>` для ограничения доступа только локальным хостом независимо от настроек OpenServer.
- `#[\SensitiveParameter]`: добавлен к параметрам с ключами/токенами/секретами в `AiClient.php` (`validateAiKey`, `buildAiHeaders`, `sendAiRequest`, `redactAiKey`, `buildAiResultRecord`, `checkAiConnection`), `SpeedCheck.php` (`runSpeedCheck`), `PageSpeedService.php` (`isValidPageSpeedKey`, `buildPageSpeedHeaders`, `runPageSpeed`, `fetchPageSpeed`, `redactPageSpeedKey`), `VulnerabilityDatabases.php` (`updateWordfenceDatabase`) и `SettingsService.php` (`maskSecret`).
- `src/Core/Logger.php`: добавлены `LOG_REDACTED`, `LOG_SECRET_ENV_NAMES`, `LOG_SECRET_MIN_LENGTH` (8) и `redactKnownSecrets()`; редактирование значений секретов применено к тексту сообщения в `logWrite()` и к строкам/`trace`/`Throwable` в `sanitizeLogContext()`; добавлен опциональный параметр `$targetFile` в `logWrite()` / `logException()` для безопасного тестирования без записи в основной лог.
- `tests/Unit/LoggerTest.php`: создано 5 тестов — на `redactKnownSecrets()`, `sanitizeLogContext()` и отсутствие утечки ключей (включая первые 15 символов) в `trace` и записанном логе при исключениях внутри `checkAiConnection()`/`sendAiRequest()`, `runSpeedCheck()` и `updateWordfenceDatabase()`.
- Целевые тесты (`LoggerTest|AiClientTest|SpeedCheckTest|PageSpeedServiceTest|SettingsServiceTest|VulnerabilityDatabasesTest`): 131 тест, 332 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 1008 тестов, 3622 assertions. Живой запрос `https://service-diagnostic-site.local/` через OpenServer вернул HTTP 200; проверка HTTP 403 со стороннего IP не выполнялась (нет второго хоста). Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** пункт 13 плана ревью (`ExportController.php` — rate limit на PDF; освобождение блокировки сессии в контроллерах).

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 7 плана ревью (`review-paln.md`, Волна 2) — добавлен разбор `affected[].versions` в `OsvClient.php`. **Волна 2 завершена (пункты 6, 7).**
- `OsvClient.php` (`parseOsvResponse()`, `parseOsvExactVersions()`): добавлена обработка точных версий из `affected[].versions`. Валидация выполняется через `normalizeVersion()`, не-версии (например, `dev-main`) отбрасываются, ведущая `v` снимается (с сохранением пре-релизных суффиксов для корректного сравнения), а версии, ещё не покрытые `ECOSYSTEM` / `SEMVER`-диапазонами этого `affected`, добавляются как точные диапазоны `[v, v]` с `from_inclusive = true`, `to_inclusive = true`.
- `OsvClientTest.php`: добавлены 2 теста — на советы без `ranges` / только с `GIT` (включая `v`-префикс, пре-релиз и `dev-main`) и на отсутствие дублирования версий, уже покрытых `ECOSYSTEM`-диапазоном.
- Целевые тесты (`OsvClientTest|VulnerabilityServiceTest|VulnerabilityCheckTest`): 53 теста, 293 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 1003 теста, 3598 assertions. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт). Живой запрос к `api.osv.dev` не запускался.
**Что следующее:** Волна 3 — пункт 14 (`public/.htaccess` с `Require local`, `#[\SensitiveParameter]` в `AiClient.php` и `SpeedCheck.php`, редактирование секретов в `Logger.php`) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 6 плана ревью (`review-paln.md`, Волна 2) — исправлено сравнение версий в `VersionService.php` (пропуск CVE и ложные срабатывания).
- `VersionService.php`: добавлены константы `VERSION_LEADING_V_PATTERN`, `VERSION_TRAILING_ZEROS_PATTERN` и функция `canonicalVersionForCompare()`, которая приводит строку к нижнему регистру, снимает ведущую `v` и хвостовые `.0` в числовой части (`6.4.0` → `6.4`, `6.0.0-rc1` → `6-rc1`), сохраняя суффиксы пре-релизов (`-dev`, `-alpha`, `-beta`, `-rc`) и патчей (`-pN`). `compareVersions()` переведена на `canonicalVersionForCompare()`; `VERSION_PATTERN` и `normalizeVersion()` не менялись.
- Тесты: добавлены кейсы в `VersionServiceTest` (11 случаев сравнения `.0`, пре-релизов и `-pN`), `VulnerabilityServiceTest` (границы с `.0` и пре-релизами в диапазонах) и `OutdatedCheckTest` (`eol_below = '5.0.0'` на версии `5.0` не даёт ложного `unsupported`).
- Целевые тесты (`VersionServiceTest|VulnerabilityServiceTest|VulnerabilityCheckTest|OutdatedCheckTest|VersionFingerprintsTest|VersionSourcesTest`): 97 тестов, 412 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 1001 тест, 3584 assertions. Проверка на локальной базе Wordfence: для WordPress `6.4` число найденных уязвимостей выросло с 17 до 43 (совпадает с `6.4.0`), включая `CVE-2024-31211`. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** пункт 7 (`OsvClient.php` — поддержка `affected[].versions`) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 12 плана ревью (`review-paln.md`) — устранены падения воркера от `E_WARNING → ErrorException` (`parse_str`, `dns_get_record`, `filemtime`) и исключений резолвера. **Волна 1 завершена (пункты 3, 4, 5, 8, 10, 12).**
- `StackDetector.php` (`extractQueryVersion()`): `parse_str()` (ограниченная `max_input_vars`) заменена разбором query через `preg_match_all()` только по ключам `ver`/`v`/`version`, с сохранением приоритета `STACK_VERSION_QUERY_KEYS`, перезаписи последнего значения и валидации `extractVersionString(..., true)`.
- `UrlValidator.php` (`resolveHostIps()`): убрана гоночная проверка `checkdnsrr()`, а `dns_get_record(..., DNS_AAAA)` выполняется под перехватом `set_error_handler` + `try/catch` (без `@`), чтобы сбой AAAA не отменял проверку по A-записям и не ронял шаг. Добавлены опциональные `$ipv4Lookup` / `$aaaaLookup` для офлайн-тестирования.
- `BrowserService.php` (`getBrowserProfileModifiedTime()`, `removeStaleBrowserProfiles()`): обращение к `filemtime()` вынесено в `getBrowserProfileModifiedTime()` под `set_error_handler` + `try/catch`, при гонке удаления профиля другим процессом возвращается `null`.
- `HttpClient.php` (`prepareRequestTarget()`): вызов `$resolver($name)` обёрнут в `try/catch (Throwable)`, возвращая штатную ошибку `['error' => ...]`, не роняя шаг.
- Тесты добавлены в `StackDetectorTest`, `UrlValidatorTest`, `BrowserServiceTest` и `HttpClientTest`. Целевые тесты: 172 теста, 615 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 988 тестов, 3566 assertions (0 предупреждений). Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** Волна 2 — пункт 6 (`VersionService.php` — сравнение версий и пропуск CVE / ложные срабатывания) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 10 плана ревью (`review-paln.md`) — добавлен `use RuntimeException` в `ExportController`.
- `ExportController.php`: добавлен импорт `use RuntimeException;` (без него `sendPdf()` искал несуществующий `App\Controllers\RuntimeException`); в `sendPdf()` проверка `is_file()` / `is_readable()` перенесена до `filesize()`, чтобы при отсутствующем файле не генерировалось предупреждение `stat failed`. Остальные контроллеры в `src/Controllers/` просканированы — неимпортированных глобальных классов не обнаружено.
- `ExportControllerTest.php`: добавлены 2 теста `sendPdf()` (отсутствующий файл и пустой файл) на `RuntimeException` без PHP warnings.
- `ExportControllerTest|PdfServiceTest`: 13 тестов, 44 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 984 теста, 3554 assertions. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** пункт 12 (`E_WARNING → ErrorException` в `StackDetector::extractQueryVersion()`, `UrlValidator::resolveHostIps()`, `BrowserService::removeStaleBrowserProfiles()` и `HttpClient::prepareRequestTarget()`) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 8 плана ревью (`review-paln.md`) — устранены падения на невалидном UTF-8 и слишком длинных URL.
- `FindingModel.php` (`encodeFindingEvidence()`, `insertFinding()`) и `AiResultModel.php` (`encodeAiResultJson()`): добавлен флаг `JSON_INVALID_UTF8_SUBSTITUTE` при кодировании JSON, чтобы битые байты (например, с сайтов в windows-1251) не вызывали `JsonException`.
- `PageModel.php` (`normalizeCheckedPageUrl()`, `insertCheckedPage()`): добавлена константа `CHECKED_PAGE_URL_MAX_LENGTH = 2048`, очистка URL от невалидного UTF-8 через `mb_scrub(..., 'UTF-8')` и усечение до 2048 символов под колонку `checked_pages.url`.
- `FindingFactory.php` (`makeFinding()`): добавлена очистка `title` и `location` через `mb_scrub(..., 'UTF-8')` (с явным `'UTF-8'` в `mb_substr()`).
- `StackDetector.php` (`addStackSignal()`, `detectThemesAndPlugins()`, `finalizeStackItems()`): добавлен `mb_scrub(..., 'UTF-8')` для имён тем/плагинов после `rawurldecode()`, версий и деталей доказательств стека.
- Тесты: создан `tests/Unit/ModelEncodingTest.php` (3 теста), добавлены тесты в `FindingFactoryTest` и `StackDetectorTest`. Целевые тесты (`ModelEncodingTest|FindingFactoryTest|StackDetectorTest`): 57 тестов, 388 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 982 теста, 3550 assertions. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт). Живая диагностика сайтов в windows-1251 и запись в MySQL отложены на общую E2E-проверку.
**Что следующее:** пункт 10 (`ExportController.php` — `use RuntimeException`) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 5 плана ревью (`review-paln.md`) — устранена возможность DNS-rebinding из-за рассинхрона `CURLOPT_URL` и `CURLOPT_RESOLVE`.
- `HttpClient.php` (`prepareRequestTarget()`, `httpRequest()`): добавлено отклонение URL с пробельными/управляющими символами (включая NBSP и не-UTF-8 байты) и портом вне диапазона `1..65535`; `prepareRequestTarget()` теперь пересобирает и возвращает канонический `url` из нормализованного хоста (без конечной точки, в нижнем регистре, IDN → punycode, IPv6 в скобках, без `#фрагмента`). `httpRequest()` передаёт в транспорт и сохраняет в `$currentUrl` именно этот канонический `url`, совпадающий с ключом `CURLOPT_RESOLVE`.
- `HttpClientTest.php`: добавлены тесты на канонизацию хоста и отбрасывание фрагмента, преобразование IDN в punycode, отклонение пробелов/управляющих символов/порта `0` до обращения к DNS, а также сквозной тест `httpRequest()` с редиректом на кириллический домен.
- `HttpClientTest|SslCheckTest`: 65 тестов, 131 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 977 тестов, 3523 assertions. Новых записей в `storage/logs/app.log` нет (размер остался 10 160 байт). Живой curl к внешнему хосту в сеть не запускался.
**Что следующее:** пункт 8 (`FindingModel.php`, `AiResultModel.php`, `FindingFactory.php`, `PageModel.php`, `StackDetector.php` — невалидный UTF-8 и длинные URL) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 4 плана ревью (`review-paln.md`) — устранена уязвимость к gzip-бомбе в `SeoCheck::parseSitemap()`.
- `SeoCheck.php`: подключён `HttpClient.php` для гарантированного наличия `HTTP_MAX_BODY_BYTES`, добавлена константа `SEO_SITEMAP_MAX_DECODED_BYTES = HTTP_MAX_BODY_BYTES` (5 МБ) и функция `decodeSitemapGzip()`, вызывающая `gzdecode($body, $maxBytes + 1)` под временным перехватом предупреждений (без `@`). При превышении лимита или повреждении потока `parseSitemap()` возвращает `null`, что обрабатывается шагом SEO как ошибка чтения sitemap (`sitemap_error`) без OOM и падения воркера.
- `SeoCheckTest.php`: добавлены тесты на ~64 МБ потоковую gzip-бомбу (проверка `null`, роста пика памяти `memory_get_peak_usage()` и состояния `sitemap_error` в `runSeoCheck()`), границу лимита (`100`/`101` байт) и повреждённый gzip-поток.
- `SeoCheckTest`: 32 теста, 104 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 969 тестов, 3497 assertions (0 предупреждений). Отдельная проверка в CLI под обработчиком `E_WARNING → ErrorException` на ~100 МБ gzip-бомбе (101 998 байт сжатых): `parseSitemap()` вернула `null` с общим пиком памяти 7.68 МБ (`+5.65 МБ`). Новых ошибок/предупреждений в `storage/logs/app.log` нет (размер остался 10 160 байт).
**Что следующее:** пункт 5 (`HttpClient.php` — DNS-rebinding и рассинхрон `CURLOPT_URL` и `CURLOPT_RESOLVE`) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** выполнен пункт 3 плана ревью (`review-paln.md`) — устранено переполнение `findings.details` и падение воркера при сохранении находок.
- `FindingFactory.php` (`makeFinding()`): добавлена константа `FINDING_DETAILS_MAX_BYTES = 60000`; ненулевой `details` очищается через `mb_scrub(..., 'UTF-8')` и безопасно усекается по байтам через `mb_strcut(..., 0, FINDING_DETAILS_MAX_BYTES, 'UTF-8')` с запасом под `TEXT` (65 535 байт).
- `VulnerabilityCheck.php` (`makeVulnerabilityFinding()`): добавлена константа `VULNERABILITY_DETAILS_MAX_BLOCKS = 15`; уязвимости сортируются по скорректированной серьёзности и убыванию CVSS, в `details` выводятся первые 15 блоков и строка о количестве остальных, а полный список сохраняется в `evidence['vulnerabilities']`. На локальной базе Wordfence размер `details` снизился с 83 675 до 18 778 байт для WordPress 6.0.0 (65 уязвимостей) и с 149 988 до 15 082 байт для WordPress 4.7 (142 уязвимости).
- `DiagnosticRunner.php` (`finalizeStepPersistence()`, `executeStepsWithDb()`): запись каждой находки обёрнута в `try/catch`; ошибка БД логируется и переводит шаг в `failed` (с префиксом `Не удалось сохранить находки: `), не прерывая сохранение остальных находок, вызов `finishStep()`, обновление прогресса и продолжение цикла шагов.
- Целевые тесты (`FindingFactoryTest`, `VulnerabilityCheckTest`, `DiagnosticRunnerTest`): 70 тестов, 325 assertions. Полный прогон PHPUnit на PHP 8.3 OpenServer: 967 тестов, 3487 assertions. Новых ошибок/предупреждений в `storage/logs/app.log` нет (размер файла не изменился — 10 160 байт). Живая диагностика сайтов и запись в MySQL не запускались (отложено на общую E2E-проверку).
**Что следующее:** пункт 4 (`SeoCheck::parseSitemap()` — защита от gzip-бомбы) после подтверждения владельца.

---

**Дата:** 20.11.2026
**Что сделано:** актуализирована документация разработки для единой и согласованной работы в Antigravity и Claude Code.
- `AGENTS.md` и `CLAUDE.md`: сделаны единой точкой входа без дублирования (`CLAUDE.md` подключает `@AGENTS.md`), добавлены фактические инварианты среды (PHP 8.3 OpenServer, без входа/users, UI на Tabler, кросс-средовая проверка UI).
- `.claude/rules/general.md`, `php.md`, `design.md`: заменены Cursor-ключевые слова (`alwaysApply`/`globs`) на нативный синтаксис Claude Code (`paths:`), убраны устаревшие шаблонные правила (`design-template`, `.docs/ref/prototypes/`, `tooManyAttempts()`/`hitRateLimit()`, логин/роли/деньги), приведены в соответствие с фактическим кодом (`takeRateLimitAttempt()`, `App\Controllers`, Tabler 1.6.1, `$isPdfExport`, PHP 8.3 OpenServer).
- `.docs/dod-global.md`, `.docs/prompts/*.md`, `current-pipeline.md`, `README.md`: синхронизированы с фактическим проектом и поддержкой как плановых фаз, так и отдельных правок/багфиксов.
- Прогон полного набора PHPUnit на PHP 8.3 OpenServer: 964 теста, 3432 assertions (код приложения не менялся).
**Что следующее:** продолжать разработку или исправления по ревью в Antigravity или Claude Code по обновлённому регламенту.

---

**Дата:** 19.11.2026
**Что сделано:** исправлена гонка файлового rate limit по результатам ревью приложения.
- Раздельные проверка и увеличение счётчика заменены атомарной операцией под эксклюзивной файловой блокировкой;
  параллельные запросы больше не могут пройти лимит по одному значению.
- Новая диагностика расходует попытку только после успешной валидации формы. Повтор диагностики и перезапуск ИИ
  используют тот же атомарный механизм.
- `RateLimitTest`: 4 теста, 13 assertions. Полный набор на PHP 8.3 OpenServer: 964 теста, 3432 assertions.
  Диагностики сайтов, Chrome и внешние API не запускались; новых записей в `app.log` нет.
**Что следующее:** исправить следующий подтверждённый пункт ревью по выбору владельца.

---

**Дата:** 19.11.2026
**Что сделано:** Phase 5, таск 7 «Повторная диагностика и сравнение» ✅. **Фаза 5 завершена.**
- Добавлен POST-повтор завершённой или частичной диагностики: CSRF, отдельный лимит 5 запусков за 60 секунд,
  копирование сохранённых `url`, `host`, `problem_text`, `product_url`, назначение последнего запуска хоста в
  `previous_id` и запуск существующего полного воркера. Ошибочные статусы и сбой запуска дают понятный флеш.
- Добавлен `GET /compare?a={id}&b={id}` для двух `done` / `failed` диагностик одного host. Факты разделяются по
  `fingerprint` на «Исправлено», «Осталось», «Появилось»; ИИ-находки показаны отдельно «Было / Стало», без
  ложного сопоставления. Стек показывает смену известных версий, новые и исчезнувшие плагины.
- Новый экран и действия отчёта собраны из Tabler, без CSS и JavaScript. Динамические значения экранированы,
  частичные и пустые состояния объяснены; повтор и сравнение не попадают в PDF.
- Реальный HTTP-сценарий: повтор `example.com` #144 создал #168 с `previous_id = 144`, воркер дошёл до `done`,
  незавершённое сравнение до этого отклонялось, готовое открылось. Неверный CSRF, одинаковые id, разные хосты и
  заполненный лимит не создали лишних записей. На паре `cleansan.ru` #133 → #141 реально показаны 1 исправленная,
  18 оставшихся и 1 новая находка.
- Headless Chrome: отчёт #168 и сравнение #144 → #168 проверены на 360 / 768 / 1280 px — нет горизонтальной
  прокрутки, JS-ошибок и действий ниже 44 px. В `app.log` появилась только ожидаемая warning-запись проверки
  лимита, новых PHP errors / warnings нет.
- `DiagnosticComparerTest`: 8 тестов, 40 assertions; полный набор на PHP 8.3 OpenServer — 963 теста,
  3426 assertions.
**Что следующее:** все запланированные фазы 0–5 завершены; следующий продуктовый шаг пока не запланирован.

---

**Дата:** 19.11.2026
**Что сделано:** Phase 5, таск 6 «Экспорт в PDF» ✅.
- Добавлен `GET /diagnostics/{id}/pdf?version=full|client`: строгая проверка версии и статуса диагностики,
  понятные флеши при неверном запросе или сбое Chrome, безопасное имя скачиваемого файла и удаление временных файлов.
- Полный и клиентский PDF собираются теми же builder и View, что экранные отчёты. Tabler, тема, печатные стили и
  принадлежащие диагностике скриншоты локализуются в `file://`; Chrome запускается с отдельным временным профилем.
- В отчёты добавлены кнопки «PDF полный» и «PDF для клиента». Печатный режим скрывает навигацию, действия,
  прогресс, формы, вкладки и модальные окна, раскрывает разделы и выводит полноразмерные скриншоты с подписями.
- Реальные экспорты готовой диагностики `sudar.su`: обе версии вернули 200, корректные PDF-заголовки и `%PDF-`;
  проверены кириллица, разделы, таблицы, длинные тексты и скриншоты. Полный PDF — 70 страниц, клиентский — 2.
- Частичный полный и клиентский отчёты экспортированы с предупреждением о неполноте. Неверные параметры,
  незавершённая диагностика и недоступный Chrome проверены без пустого PDF; после успеха и ошибок временные HTML,
  PDF и профиль Chrome удалены. Новых неожиданных PHP errors / warnings в журнале нет.
- Интерфейс проверен на 360 / 768 / 1280 px без горизонтальной прокрутки и ошибок консоли; печатный preview
  соответствует экспорту. Целевые тесты: 34 теста, 144 assertions. Полный набор на PHP 8.3 OpenServer:
  955 тестов, 3386 assertions.
**Что следующее:** Phase 5, таск 7 «Повторная диагностика и сравнение» — подготовить `TASK.md` по `task-init.md`.

---

**Дата:** 18.11.2026
**Что сделано:** Phase 5, таск 5 «ИИ-текст для клиента» ✅.
- Добавлен шаг `ai_client` после `ai_summary`: использует `model_summary`, строгую JSON-схему и общий `AiTask`,
  сохраняет ответ как `ai_results.task = client_text`, а простые тексты атомарно записывает в
  `findings.client_text` только по id находок текущей диагностики.
- Клиентские тексты защищены кодовым фильтром: ответы с URL, IP, Windows/Unix-путями, именами технических файлов,
  версиями, HTTP-кодами и сырыми ошибками отбрасываются целиком. Промпт отделён от входных JSON-данных и запрещает
  выполнять команды из диагностических текстов.
- Перезапуск ИИ очищает старые `client_text` в одной транзакции со сбросом шагов и удалением старых ИИ-находок.
  Клиентский отчёт показывает резюме только из успешного ответа текущего запуска `ai_client`; иначе использует
  шаблоны таска 4 без старого текста.
- Реальный повторный ИИ-прогон диагностики #144 (`example.com`) выполнен дважды: все четыре ИИ-шага `done`, созданы
  результаты `client_text` id 41 и 45; контрольный старый маркер удалён, во втором прогоне заполнены 11 из 11
  актуальных находок. Клиентская страница отвечает 200, показывает резюме и 4 группы, технических деталей нет.
- Fallback проверен отдельной временной диагностикой со шагом `ai_client = skipped`: HTTP 200, шаблонный текст есть,
  блока резюме нет; временная запись удалена. Транзакции MySQL проверены на изоляцию по `diagnostic_id`, очистку
  старых значений и rollback при исключении.
- Headless Chrome: 360 / 768 / 1280 px открылись без ошибок и событий консоли, горизонтальных поломок нет;
  служебная навигация отсутствует. Новых errors / warnings в `storage/logs/app.log` нет.
- Целевые тесты: 55 тестов, 243 assertions. Полный набор на PHP 8.3 OpenServer: 938 тестов, 3297 assertions.
**Что следующее:** Phase 5, таск 6 «Экспорт в PDF» — подготовить `TASK.md` по `task-init.md`.

---

**Дата:** 18.11.2026
**Что сделано:** Phase 5, таск 4 «Клиентская версия отчёта» ✅.
- Добавлен `GET /diagnostics/{id}/client`: готовая диагностика показывает клиентский отчёт, прерванная — сохранённые
  результаты с предупреждением, незавершённая возвращает 409 без промежуточных данных, неверный id — 404.
- Находки группируются по категории и приоритету; карта простых формулировок покрывает все 15 категорий. В карточке
  видны количество, «что произошло», «чем грозит» и «что делать». `client_text` важнее шаблона и не дублируется;
  неизвестные категории получают безопасный fallback без внутреннего кода.
- Builder передаёт во View только разрешённые клиентские поля: технические заголовки, детали, адреса, IP, версии,
  коды, evidence, стек и скриншоты не выводятся. `FindingModel` теперь читает существующий `client_text`.
- Экран собран из локального Tabler без новых CSS / JS и без главного меню; технический отчёт получил кнопку
  «Клиентская версия». Режим печати скрывает header и служебные кнопки.
- End-to-end: реальная диагностика #129 (`done`, несколько однотипных находок) — 200, сгруппированные карточки,
  технических данных в видимом тексте нет; временные `failed` / `queued` — 200 с предупреждением / 409, после
  проверки записи удалены; неверный и отсутствующий id — 404.
- DevTools на 360 / 768 / 1280 px: горизонтальной прокрутки нет, кнопки 48 px (в техническом отчёте минимум 44 px),
  консоль чистая; печатный режим скрывает навигацию. После проверки новых errors / warnings в `app.log` нет.
- Новые тесты: 11 тестов, 104 assertions. `composer test` на PHP 8.3: 924 теста, 3244 assertions.
**Что следующее:** Phase 5, таск 5 «ИИ-текст для клиента» — подготовить `TASK.md` по `task-init.md`.

---

**Дата:** 18.11.2026
**Что сделано:** Phase 5, таск 3 «Сценарий корзины в отчёте» ✅.
- В технический отчёт добавлена карточка «Сценарий заказа»: общий исход, последний достигнутый шаг, число ушедших и
  заблокированных изменяющих запросов, статусы шагов, адреса, заметки и ошибки консоли.
- Каждый шаг показывает только принадлежащий диагностике скриншот через существующую галерею и доступное модальное
  окно; отсутствующий или чужой `screenshot_id` даёт пустое состояние. Старые и повреждённые сводки карточку не
  создают. Неизвестные коды и весь внешний текст выводятся безопасно.
- `summary.checkout` закреплён тестом в пакете фактов ИИ; страницы сценария не возвращаются в `pack.pages`.
- Живой прогон sudar.su: четыре шага до формы оформления, четыре рабочие миниатюры, ушло 0, заблокировано 5.
  Живой прогон Citilink с доступным товаром: красный итог на шаге «Оформление», последний достигнутый шаг «Корзина»,
  четыре снимка, ушло 0, заблокировано 37. Тестовые диагностики и файлы удалены.
- Headless Chrome на 360 / 768 / 1280 px: горизонтальной прокрутки нет, кнопки галереи не меньше 44 px, модальные
  окна открываются, событий ошибок консоли нет. Новых errors / warnings в `storage/logs/app.log` нет.
- Целевые тесты: 51 тест, 300 assertions. Полный набор на PHP 8.3 OpenServer: 913 тестов, 3140 assertions.
**Что следующее:** Phase 5, таск 4 «Клиентская версия отчёта» — подготовить `TASK.md` по `task-init.md`.

---

**Дата:** 18.11.2026
**Что сделано:** Phase 5, таск 2 «Сценарий корзины: проход в браузере» ✅.
- Добавлен шаг `checkout` после `browser`: отдельный Chrome с одной вкладкой проходит товар → добавление → корзина →
  оформление на 1366 px; каждый шаг сохраняет страницу, снимок и компактные факты об ошибках консоли.
- Заказ не отправляется: до оформления блокируются изменяющие запросы на адреса заказа, с начала оформления — все
  POST / PUT / PATCH / DELETE; тела и query запросов не сохраняются. Кнопки быстрого заказа, оформления и оплаты
  исключены стоп-листом, поля не заполняются и кнопки оформления не нажимаются.
- Результаты сценария преобразуются в отдельные находки `checkout` или понятный `skipped`; страницы сценария не
  отправляются в `ai_visual` и страницы пакетов `ai_problem` / `ai_summary`, не дублируют снимки в обычной галерее.
- По ревью исправлено: защита от ботов распознаётся и по DOM, реально открытому в Chrome; повреждённое CDP-событие
  не обрывает сценарий и оставляет запрос приостановленным; доказательством неудачи остаётся первый приоритетный
  адрес, а созданный перехватом `ERR_BLOCKED_BY_CLIENT` не считается ошибкой сайта.
- Живые прогоны (`availability → stack → crawl → checkout`): sudar.su и cleansan.ru дошли до настоящих форм
  оформления (проверено по снимкам); citilink.ru дошёл до корзины и дал находку «нет формы оформления» на `/order/`.
  Во всех трёх `checkout_sent = 0`, суммарно заблокировано 55 изменяющих запросов. Для каждого шага созданы строки
  `checked_pages`, `screenshots` и файлы JPG; `summary_json.checkout` — 1,1–1,3 КБ; пересечений страниц сценария с
  ИИ и их снимков с обычной галереей нет. После каждого исхода `storage/temp` пуст, тестовые диагностики и снимки
  удалены. Citilink 18.11 не показал защиту от ботов; соответствующая ветка подтверждена тестом.
- Финальный `composer test` на PHP 8.3 OpenServer — 907 тестов, 3101 assertion. После финальных прогонов новых
  записей в `app.log` нет; одна ошибка CDP от первого прогона в 14:24 предшествует исправлению и не повторилась.
**Что следующее:** Phase 5, таск 3 «Сценарий корзины в отчёте» — подготовить `TASK.md` по `task-init.md`.

---

**Дата:** 16.11.2026
**Что сделано:** Phase 5, таск 1 «Открытые служебные файлы» ✅. Фаза 5 — в работе.
- Шаг `exposed_files` после `seo` («Служебные файлы»): `Checks/ExposedFilesCheck.php`, список путей и признаков — `data/exposed-files.php` (21 путь в 8 группах: `.git`, `.env`, копии конфигов, дампы, архивы, phpinfo, Adminer, логи).
  - Эталон «мягкого 404» — случайный адрес `/sd-<16 hex>.txt`. Файл открыт, только если: код 200 / 206, путь после редиректов тот же, тело не равно эталону, сработал признак содержимого (регулярные выражения, `not_html`).
  - Серьёзность: `critical` — `.git`, `.env`, конфиги, дампы, архивы; `high` — phpinfo, Adminer; `medium` — логи.
  - В `evidence` только `status`, `bytes_read`, `content_type`, `signature`; содержимое файла никуда не пишется.
  - Нет главной / эталон без ответа / защита от ботов → `skipped` с причиной. Heartbeat после каждого запроса.
- `HttpClient`: необязательный предел `httpRequest(..., $maxBytes)` → `Range: bytes=0-N`, `Accept-Encoding: identity`, обрыв загрузки; `EXPOSED_FILE_MAX_BYTES` = 16 КБ. Без параметра — как раньше.
  - В режиме предела — HTTP/1.1: curl 8.7.1 после обрыва потока HTTP/2 ждёт тайм-аут (30 с на запрос; cleansan.ru шёл 193 с, стало 9 с).
- Проверено вживую: sudar.su 11 с, cleansan.ru 9 с, citilink.ru 7 с, wordpress.org/news 28 с — 21 путь, ложных находок нет. Шаг через БД на sudar.su — «Служебные файлы — Готово» на странице диагностики; тестовая запись удалена, новых записей в `app.log` нет.
  - Не проверено вживую: сайт с настоящим открытым файлом — только тестами.
  - Не сделано (вне scope): запросы без предела по-прежнему идут по HTTP/2 — при обрыве на 5 МБ возможно такое же ожидание.
- Тесты — 856 OK на PHP 8.3 OpenServer (+ `ExposedFilesCheckTest`, 4 теста в `HttpClientTest`, порядок шагов в `DiagnosticRunnerTest`).
**Что следующее:** Phase 5, таск 2 «Сценарий корзины: проход в браузере».

---

**Дата:** 16.11.2026
**Что сделано:** Phase 4, таск 5 «Перезапуск ИИ-анализа» ✅. Фаза 4 завершена.
- `POST /diagnostics/{id}/ai-rerun` (`DiagnosticController::rerunAi`): CSRF → лимит 3 за 60 с (`ai_rerun`) → атомарно `done → queued` (`queueAiRerun()`) → `launchWorker($id, WORKER_MODE_AI_ONLY)` → redirect на экран прогресса.
  - Диагностика не в `done` (выполняется, прервана, нет такой) → флеш «ИИ-анализ не перезапущен…», воркер не запускается.
  - Воркер не запустился → `restoreDiagnosticDone()`, отчёт прежний, флеш с ошибкой.
- Воркер: `bin/worker.php <id> --ai` → `runAiRerun()`.
  - `claimAiRerun()`: `queued → running`, `started_at` первого запуска не меняется.
  - `resetAiSteps()` одной транзакцией: недостающие ИИ-шаги добавляются в конец списка, существующие сбрасываются в `pending`, находки `source = 'ai'` удаляются, прогресс — 0. История `ai_results` остаётся; отчёт показывает последний запуск (`filterReportRunAiResults()` по `started_at` шага).
  - Выполняются только `getAiSteps()` (`ai_problem`, `ai_visual`, `ai_summary`); общий цикл с записью в БД вынесен в `executeStepsWithDb()`, его использует и `runDiagnostic()`.
- `JobLauncher`: режимы `full` / `ai`, флаг `--ai`, неизвестный режим → исключение.
- Отчёт: кнопка «Перезапустить ИИ-анализ» (Tabler `btn-outline-purple btn-lg`, 48 px) в шапке карточки «Итоговый вывод (ИИ)», только у `done`. У старых диагностик без ИИ-шагов — та же карточка с пустым состоянием «ИИ-анализ для этой диагностики не проводился» (только во View, `ReportBuilder` не менялся).
- Проверено вживую:
  - #129 sudar.su (без ИИ-шагов): три ИИ-шага добавлены в конец, все `done` (7 гипотез, 9 поломок вёрстки, 10 шагов плана), факт-шаги не тронуты, `started_at` прежний;
  - повторный перезапуск #129: 16 старых ИИ-находок удалены → 17 новых; `ai_results` 6 → 12; «Потрачено на ИИ» 9,12 → 18,71 ₽; подписи блоков — последнего запуска;
  - POST во время работы отклонён; без CSRF — 403; 4-й запрос за минуту — «Слишком много перезапусков» + WARNING в `app.log`;
  - 360 / 768 / 1280 px без горизонтальной прокрутки, консоль на отчёте чистая, новых ошибок в `app.log` нет.
  - Не проверено вживую: перезапуск без ключа API и сбой запуска воркера.
  - «Нет запросов к сайту» подтверждено тестом на `getAiSteps()`; `app.log` HTTP-запросы не пишет.
- Тесты — 838 OK на PHP 8.3 OpenServer (`DiagnosticRunnerTest`, `JobLauncherTest`). `composer test` с PHP 8.4 из PATH падает на 53 тестах (нет `intl`/`gd`) — так было и до таска.
**Что следующее:** Phase 5 «Для клиента и углублённые проверки» — перепроверить план промптом `.docs/prompts/phase-init.md`.

---

**Дата:** 16.11.2026
**Что сделано:** Phase 4, таск 4 «Итоговый вывод» ✅.
- Шаг `ai_summary` после `ai_visual` («Итоговый вывод (ИИ)»).
  - Факты, ИИ-находки и статусы шагов читаются **из БД**.
  - Нет ключа → `skipped`; ошибка API / ответ не по схеме → `failed`, ответ в `ai_results`.
  - `ai_problem` / `ai_visual` не выполнены → вывод всё равно строится, в сообщении шага «Вывод без …».
- `Ai/SummaryAnalysis.php` + `config/prompts/summary.txt`.
  - В запрос: пакет фактов (`buildFactPack()`) и отдельный список `ai_findings` (гипотезы и поломки вёрстки, не больше 60, объяснение обрезано до 300 символов).
  - Схема `strict`: `verdict`, `severity`, `steps[]` (`title`, `action`, `severity`, `finding_ids`, `stack_ids`, `ai_finding_ids`); не больше 10 шагов; пустой `verdict` → не по схеме.
  - Шаг плана без допустимой ссылки отбрасывается (общий `validateAiEvidence()`).
  - Итог хранится только в `ai_results.parsed_json`, находок шаг не создаёт (план — не поломка).
- Стоимость (решение владельца: текущая цена из каталога, поэтому «≈»).
  - `calculateAiCostKopecks()` в `AiModels.php`: целые копейки, `intdiv` с округлением, без float; нет токенов → 0, нет цены → null. `formatAiCost()`: «4,20 ₽», «< 0,01 ₽».
  - Каталог передаёт `DiagnosticController` (`loadRouterAiModels()`, только если есть `ai_results`; сбой → отчёт без сумм).
  - Подпись всех трёх ИИ-блоков: модель · дата · токены · ≈ N ₽ («Вёрстка» — сумма по ответам запуска), без цены — «стоимость неизвестна».
  - По просьбе владельца: в «Сводке» блок «Потрачено на ИИ» — сумма по **всем** `ai_results` диагностики (все задачи, запуски, неудачные), число запросов, токены, «без учёта N запросов», если цены нет.
- Отчёт: карточка «Итоговый вывод (ИИ)» первой — бейдж общей серьёзности, вывод, план «Шаг N» со ссылками «Опирается на» (`#finding-`, `#stack-`, `#ai-finding-`), «отброшено N», состояния skipped / failed, у старых диагностик карточки нет. У гипотез и поломок появились якоря `ai-finding-{id}`. Все три ИИ-карточки отделены полосой `card-status-top bg-purple` и бейджем «ИИ».
- Проверено вживую:
  - #145 sudar.su с жалобой: три ИИ-шага `done`, 10 шагов плана, отброшено 0; первый шаг — падение `script_additional.js` на checkout и SmartCaptcha (прямая причина жалобы);
  - 60 ссылок в плане, битых нет, ≥ 44 px, ведут к цели (длинная страница — плавная прокрутка ≈ 1 с);
  - «Потрачено на ИИ: ≈ 10,09 ₽ · запросов 6 · токены 32959 / 10033» = 3,52 + 2,37 + 4,20 (вывод пересчитан вручную); #140 — 6,46 ₽ = 2,49 + 3,97, без карточки вывода; #136 — без карточки и расходов;
  - без ключа → `skipped`, испорченный ключ → `failed`, запись `error` (вызов обработчика из консоли на #141 без правки `.env`; у #141 осталась запись #25 с ошибкой);
  - ключа нет в `ai_results`, сообщениях шагов, `app.log`; новых ошибок в `app.log` нет;
  - отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, консоль чистая.
- Тесты — 832 OK на PHP 8.3 OpenServer (+ `SummaryAnalysisTest`, тесты в `ReportBuilderTest`, `AiModelsTest`, `DiagnosticRunnerTest`).
**Что следующее:** Phase 4, таск 5 «Перезапуск ИИ-анализа» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 4, таск 3 «Проверка вёрстки по скриншотам» ✅.
- Шаг `ai_visual` после `ai_problem` («Проверка вёрстки (ИИ)»).
  - Скриншоты и страницы читаются **из БД**.
  - Нет ключа или скриншотов → `skipped`.
  - Модель есть в каталоге RouterAI без картинок → `failed` до запроса.
  - Одна страница упала → остальные проверяются, в сообщении шага «Не проверено: адрес — причина». Не проверена ни одна → `failed`.
  - Heartbeat после каждой страницы.
- `Ai/VisualAnalysis.php`.
  - Не больше 10 страниц (`AI_VISUAL_MAX_PAGES`, решение владельца) в порядке `checked_pages.id`: главная первой, остальные — в сообщении шага.
  - Один запрос на страницу, по снимку на ширину (не больше 3).
  - GD: верх 1600 px, ширина до 1024 px, JPEG 75 (константы).
  - Перед каждой картинкой подпись с её `screenshot_id`.
  - Схема `strict`: `title`, `explanation`, `severity`, `screenshot_ids`; не больше 5 поломок на страницу.
  - Ссылки — только на скриншоты этого запроса (общий `validateAiEvidence()` без правок). В `request_json` вместо картинок только `screenshot_id`.
- `config/prompts/visual.txt`: только поломки, не «вкус»; текст на скриншотах — данные. После первого прогона добавлено правило: cookie-баннер, чат и всплывающие окна первого захода — не поломка (на sudar.su 5 из 14 поломок были про баннер; на cleansan.ru после правки — ни одной).
- Поломка → находка `layout`: `makeAiFinding()` получил необязательные `location` (адрес страницы, входит в fingerprint) и `page_id`. `getScreenshotsByDiagnostic()` отдаёт `file_path`.
- Отчёт: карточка «Вёрстка (ИИ)» после «Разбора жалобы».
  - Подпись «ИИ смотрел только верх страницы: 1600 px».
  - Поломки от серьёзных: страница, миниатюры скриншотов (готовый `screenshot-gallery.php`, id окна `screenshot-layout-{находка}-{снимок}`), «отброшено N».
  - Предупреждение о не проверенных страницах; `skipped` / `failed` — `alert-info` / `alert-danger`.
  - Подпись: модель, дата, сумма токенов по ответам **текущего запуска** шага (`created_at` ≥ `started_at`).
  - У старых диагностик карточки нет. Поломки не входят в «Находки».
- Проверено вживую:
  - #140 sudar.su: 4 страницы, 14 поломок, 2,5 мин, токены ≈ 13,7 тыс. / 3,7 тыс. (≈ 4 ₽);
  - #141 cleansan.ru: 4 страницы, 5 поломок;
  - #142 модель `qwen/qwen3-coder` (временно в настройках, возвращено): `failed` с причиной;
  - #143 испорченный ключ: `failed`, записи `error` в `ai_results`;
  - #144 без ключа (временные правки `.env`, возвращены): `skipped`;
  - #136: без карточки;
  - нет скриншотов (обработчик на пустом id): `skipped`.
- Ключа нет в БД и `app.log`, base64 в `request_json` нет. Миниатюра открывает свой скриншот. Отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, миниатюры ≥ 44 px, консоль чистая.
- Тесты — 814 OK на PHP 8.3 OpenServer (+ `VisualAnalysisTest`, тесты в `ReportBuilderTest`, `FindingFactoryTest`, `EvidenceValidatorTest`, `DiagnosticRunnerTest`).
- Замечено, не исправлено (вне scope): на #141 шаг `ai_problem` упал через 10 с с текстом «не ответил вовремя (180 с)» — это тайм-аут соединения (curl 28), а `describeAiError()` в `AiClient.php` любой тайм-аут подписывает общим временем запроса.
**Что следующее:** Phase 4, таск 4 «Итоговый вывод» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 4, таск 2 «Пакет фактов и разбор проблемы» ✅.
- Шаг `ai_problem` после `pagespeed` («Разбор проблемы (ИИ)»): факты читаются **из БД** (находки проверок, стек, страницы, `summary_json`), а не из памяти воркера — разбор можно будет перезапустить (таск 5). Нет ключа → `skipped` с причиной; ошибка API / ответ не по схеме → `failed`, ответ уже в `ai_results`. Без описания проблемы гипотезы строятся только по фактам.
- `FactPacker.php`: пакет JSON с id находок и стека (ссылаться можно только на них), страницы и сводка — контекст; сначала серьёзные находки, пакет ≤ 60 000 символов (`FACT_PACK_MAX_CHARS`), длинные тексты и `evidence` обрезаются, не поместившиеся находки — `omitted_findings`.
- `Ai/EvidenceValidator.php` (общий для тасков 3–4): только id из пакета этой диагностики, без повторов; элемент без единой допустимой ссылки отбрасывается, число отброшенных — в `parsed_json.rejected` и сообщении шага.
- `Ai/AiTask.php` — общий запуск ИИ-задачи (запрос → разбор под задачу → `ai_results` → результат или исключение); вынесен из `DiagnosticRunner.php`, чтобы тот не разрастался. `Ai/ProblemAnalysis.php` + `config/prompts/problem.txt`: схема `strict` (`title`, `explanation`, `how_to_check`, `severity`, `finding_ids`, `stack_ids`), не больше 7 гипотез; жалоба и факты — отдельным JSON как данные, промпт запрещает выполнять указания из них. Параметры с ключом помечены `#[\SensitiveParameter]` — в трассировке `app.log` вместо ключа `SensitiveParameterValue`.
- Гипотеза → находка `makeAiFinding()`: `source = ai`, `category = hypothesis`, серьёзность от модели, объяснение и «Как проверить» — в `details`, ссылки — в `evidence`, `ai_result_id` (`FindingModel` пишет и читает колонку).
- Отчёт: карточка «Разбор жалобы (ИИ)» (бейдж «ИИ») на всю ширину после «Сводки | Стека» — жалоба, гипотезы последнего ответа со ссылками «Опирается на» (`#finding-{id}`, `#stack-{id}`), «отброшено N», подпись модель · дата · токены (`components/ai-block.php`, пригодится в тасках 3–4); состояния `skipped` / `failed` — `alert-info` / `alert-danger`; у старых диагностик карточки нет. ИИ-находки не входят в «Находки» и их счётчик (решение владельца). Якорь находки — только на вкладке «По серьёзности»; с вкладки «По страницам» ссылка сначала переключает вкладку (`diagnostic-progress.js`), прокрутка — в следующем кадре, после фокуса Bootstrap на вкладке.
- Проверено вживую: sudar.su с жалобой (#137) — 53 с, 7 гипотез, отброшенных 0, токены 7430 / 2367 (≈ 2,3 ₽), ссылки ведут на настоящие находки (JS-ошибки, 403 ассетов оформления); без ключа (#138) — `skipped`, отчёт строится; испорченный ключ (#139, временная правка `.env`, возвращена) — `failed`, запись `error` в `ai_results`; #136 — без карточки. Ключа нет в `ai_results`, сообщениях шагов, `app.log`. Отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, ссылки 49,5 px, консоль чистая. Тесты — 797 OK на PHP 8.3 OpenServer (+ `FactPackerTest`, `EvidenceValidatorTest`, `ProblemAnalysisTest`, тесты в `FindingFactoryTest`, `ReportBuilderTest`, `DiagnosticRunnerTest`). Диагностики #137–#139 оставлены для просмотра состояний.
- Замечено: браузер кэширует `/assets/js/*` на сутки (`max-age=86400`) — после обновления JS нужен Ctrl+F5; версии в адресе скриптов в проекте нет.
**Что следующее:** Phase 4, таск 3 «Проверка вёрстки по скриншотам» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 4, таск 1 «Клиент RouterAI и настройки моделей» ✅. Перед началом план Фазы 4 перепроверен (`phase-init.md`): 5 тасков (добавлен перезапуск ИИ-анализа), факты для ИИ — из БД; модель по умолчанию для всех задач — `qwen/qwen4-max` с `reasoning_effort = low` (решение владельца; ≈ 19–26 ₽ за диагностику по оценке); открытый вопрос о моделях закрыт в `prd.md` и `_status.md`.
- БД: таблица `ai_results` (`database/install.php`), FK `fk_findings_ai_result` через `ALTER TABLE` — только если его нет в `information_schema` (блок `$foreignKeys`); повторный запуск ничего не меняет.
- `AiClient.php`: `POST {base}/chat/completions`, ключ только заголовком `Authorization: Bearer` (проверка символов, `redactAiKey()` в текстах ошибок), `response_format: json_schema` + `strict`, `reasoning_effort = low`, `max_tokens` константой, картинки — `image_url` base64. Тайм-аут 180 с (проверка подключения — 60 с); до 3 попыток при 429 / 5xx / сбое сети (паузы 2 и 5 с), после тайм-аута и 400 / 401 / 402 повтора нет. Разбор: текст, модель, токены; пустой ответ и обрыв по `length` — ошибка. `buildAiResultRecord()` — строка для `ai_results` без ключа, картинки заменены `screenshot_id`. `checkAiConnection()` — модель должна вернуть `{"ok": true}` по схеме.
- `AiModels.php`: `GET {base}/models` без ключа, кэш на сутки в `storage/cache/routerai/models.json` (запись через временный файл); сбой — старый кэш любой давности, неудача запоминается на 5 минут (недоступный RouterAI иначе задерживал каждое открытие настроек на ~10 с — DNS на Windows). Картинки (`input_modalities`), JSON по схеме (`response_format`), цена — целыми копейками за 1 млн токенов.
- `AiResultModel.php`: `insertAiResult()`, `getAiResultsByDiagnostic()`.
- Настройки: `AI_DEFAULT_MODEL`, `resolveModelSetting()` (пусто → модель по умолчанию), `validateModelsAgainstCatalog()` (нет в RouterAI / нет JSON по схеме / нет картинок для «Проверка вёрстки»); под полем — «Qwen: Qwen4 Max · картинки: да · 160,2 / 480,59 ₽ за 1 млн токенов»; кнопка «Проверить подключение» (сохранить → по запросу на каждую разную модель → flash с временем и токенами). Список моделей недоступен — сохранение проходит, flash `warning` (в `flash.php` добавлен этот тип).
- Проверено живыми запросами: RouterAI понимает `reasoning_effort` сверху (0 токенов рассуждений), а `reasoning.effort` — нет; неверный ключ — 401 «401 Unauthorized», чужая модель — 400 «Model '…' not found»; картинка base64 принимается; в ответе модель без провайдера (`qwen4-max`) и `usage.cost` в рублях. В настройках: проверка — «qwen/qwen4-max, 2,5 с, токены 110 / 10»; испорченный и пустой ключ (временная правка `.env`, возвращена) — понятные ошибки; несуществующая модель и `qwen/qwen3.7-max` в «Проверка вёрстки» не сохраняются, в «Разбор проблемы» — сохраняется; недоступный адрес API — сохранение с предупреждением, второе открытие 0,07 с. Пробная запись в `ai_results` (диагностика #129) — модель, ответ, токены, ключа нет; запись удалена. Ключа нет в `app.log`. Настройки на 360 / 768 / 1280 px без горизонтальной прокрутки, кнопки 48 px, консоль чистая. Тесты — 773 OK на PHP 8.3 OpenServer (+ `AiClientTest`, `AiModelsTest`, тесты в `SettingsServiceTest`, `FlashComponentTest`; фикстуры `tests/fixtures/routerai/`: настоящие ответы, `synthetic-error-429.json` — вручную).
- В настройках сохранено `qwen/qwen4-max` для всех трёх задач.
**Что следующее:** Phase 4, таск 2 «Пакет фактов и разбор проблемы» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 3, таск 4 «Скорость через PageSpeed Insights» ✅. Фаза 3 завершена.
- Шаг `pagespeed` после `browser` («Скорость (PageSpeed)»): главная — mobile, потом desktop, тайм-аут 120 с на запрос, heartbeat после каждого. Итог — `summary_json.pagespeed` (оценка, лаборатория LCP / CLS / TBT / FCP, реальные пользователи LCP / INP / CLS / FCP с `field_scope` url / origin, `runWarnings`, версия Lighthouse); сырой ответ не хранится.
- `PageSpeedService.php`: ключ только заголовком `X-Goog-Api-Key` (в `httpApiRequest()` — необязательный параметр заголовков), ключ проверяется на допустимые символы, тексты ошибок — через `redactPageSpeedKey()`. Причины: 429, `error.message` Google (неверный ключ и `FAILED_DOCUMENT_REQUEST` приходят как 400), тайм-аут curl, `runtimeError`, пустой ответ. CLS пользователей ÷ 100.
- `Checks/SpeedCheck.php`: пороги Google включительно; находки `speed` — LCP, CLS, отклик (INP, без него TBT), оценка < 50; сначала реальные пользователи, без них лаборатория; одна находка на показатель по обеим стратегиям, «плохо» / оценка < 50 → `medium`, «требует улучшения» → `low`. Нет ключа / главная не ответила / обе стратегии упали → `skipped`; одна упала → `done` с причиной в сообщении.
- Отчёт: карточка «Скорость» под «Сводкой» и «Стеком» — оценки, две таблицы (лаборатория, реальные пользователи), предупреждения и ошибки стратегий; «по всему сайту» — подписью под таблицей (в заголовке колонки на 360 px Desktop уезжал за экран). Нет данных пользователей — сообщение; шага не было — карточки нет.
- Проверено вживую (шаги параллельно): sudar.su — 34 / 46, 3 находки; cleansan.ru — 35 / 66, данные только по сайту; citilink.ru — 59 / 59, Google открыл главную; example.com с неверным ключом и без ключа — `skipped` с причиной, видно в «Не удалось проверить». Ключа нет в `app.log`, `summary_json`, сообщениях шагов, находках. Наши пороги совпали с `category` Google на всех 16 значениях настоящих ответов (тест). Отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, консоль чистая; `chrome.exe` от воркера нет. Тесты — 732 OK на PHP 8.3 OpenServer (+ `PageSpeedServiceTest`, `SpeedCheckTest`, тесты в `ReportBuilderTest`; фикстуры `tests/fixtures/pagespeed/`: настоящие ответы sudar.su урезаны, `synthetic-*` собраны вручную). Тестовые диагностики #132–#136 оставлены для просмотра.
- Остаются: диагностика длиннее на 1–2 минуты (запросы по очереди, без `curl_multi`); PageSpeed только для главной.
**Что следующее:** Фаза 4 «ИИ-анализ» — перепроверить план промптом `.docs/prompts/phase-init.md`.

**Дата:** 16.11.2026
**Что сделано:** Phase 3, таск 3 «Скриншоты на трёх ширинах» ✅.
- Таблица `screenshots` (`database/install.php`: diagnostic_id, page_id → CASCADE, viewport_width, file_path). Файлы — `storage/screenshots/<diagnostic_id>/<page_id>-<ширина>.jpg` и `…-thumb.jpg` (путь миниатюры выводится из пути снимка, схема БД не менялась).
- `BrowserService.php`: после загрузки страницы (сбор событий уже остановлен) `captureBrowserScreenshots()` — снимок всей страницы JPEG 80 (`captureBeyondViewport`, высота — max(контент, окно), предел `SCREENSHOT_MAX_HEIGHT_PX = 10000` — длинная страница обрезается, шаг не падает) и миниатюра верха 320 × 427 JPEG 70 через `clip.scale` (Chrome, а не GD — не декодировать в PHP картинку на десятки МБ). Без прокрутки перед снимком (решение владельца). Сообщение шага: «…; скриншотов: 12 из 12».
- `ScreenshotService.php` (чистые функции): клипы, пути, `resolveScreenshotPath()` (шаблон имени + `realpath` внутри папки-основы), запись обоих файлов (ошибка — исключение, частичные файлы удаляются), удаление папки диагностики. `DiagnosticRunner.php`: `storeLoadScreenshot()` — файлы + строка БД; сбой записи — `logWarning`, причина в фактах шага, шаг не падает. `ScreenshotModel.php`.
- `ScreenshotController.php`: `GET /screenshots/{id}` и `/screenshots/{id}/thumb` — `image/jpeg`, `Cache-Control: private`; нет записи / файла / путь вне `storage/screenshots/` → 404 + `logWarning`. `HistoryController.php`: удаление диагностики удаляет и папку снимков (сбой — в журнал, flash об успехе остаётся).
- Отчёт: в карточке «Проверенные страницы» под каждой открытой страницей — миниатюры по ширинам (`screenshot-gallery.php`, Tabler `img-thumbnail`, `loading="lazy"`), крупно — модальное окно Tabler (полный снимок грузится только при открытии) и «Открыть файл»; `alt` «Главная, 360 px». Снимки — по адресу страницы (`normalizeFindingLocation()`): адрес, который и главная, и каталог, показывает их в обеих строках. Нет снимков — «Скриншотов нет.» + причина пропущенного шага `browser`.
- Проверено вживую: cleansan.ru и sudar.su — 12 из 12, снимки 80–720 КБ, миниатюры 14–24 КБ, страница 360 px обрезана ровно на 10000; citilink.ru — 2 из 2 (категория и товар — 429, не открывались), воркер не упал. Подложенный файл на место папки (example.com) — шаг `done`, «скриншотов: 0 из 3», причина в `app.log`. 404: несуществующий id, `abc`, `0`, подменённый в БД путь `../../config/config.php`. Удаление из истории убирает папку и строки. Отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, Esc закрывает окно, консоль чистая; `chrome.exe` и папок `chrome-*` после диагностик нет. Тесты — 675 OK на PHP 8.3 OpenServer (+ `ScreenshotServiceTest`, тесты в `BrowserServiceTest`, `ReportBuilderTest`). Тестовые диагностики #128, #131 удалены; #129 (sudar.su) и #130 (citilink.ru) оставлены для просмотра.
- Остаются: неподвижные элементы (cookie-баннер, кнопка звонка) попадают в середину снимка всей страницы (так снимает Chrome); причина несохранённого снимка — в фактах шага и `app.log`, в отчёт не сохраняется (как в таске 2); показ в двух строках вживую не встретился — проверен тестом.
**Что следующее:** Phase 3, таск 4 «Скорость через PageSpeed Insights» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 3, таск 2 «Ошибки JS и упавшие запросы» ✅.
- Шаг `browser`: каждая страница загружается на 360 / 768 / 1366 (`Emulation.setDeviceMetricsOverride`, 360 — мобильная эмуляция), каждая загрузка — новая вкладка, после `load` 2 с ловим поздние ошибки, heartbeat после каждой загрузки и каждого повторного запроса. Сообщение: «Открыто в браузере: 4 из 4 страниц (12 загрузок); ошибок JS: 4; упавших запросов: 0».
- `BrowserService.php`: подписка на `Network.requestWillBeSent` / `responseReceived` / `loadingFailed`, `Runtime.exceptionThrown` / `consoleAPICalled`, `Log.entryAdded` (`Log.enable`); не больше 200 событий на загрузку, остальное — счётчик.
- `Checks/BrowserConsoleCheck.php` (чистые функции): события → ошибки, отсев по `data/browser-ignore.php` (счётчики, реклама, виджеты; `ERR_BLOCKED_BY_CLIENT` / `ERR_ABORTED` / отменённые; mixed content; `Ping` / `Preflight` / favicon; 401 / 403 у XHR / Fetch; 429; `Script error.`; источники `Log` network / security / deprecation …; `[Report Only]`; документ самой страницы; не-`http(s)`; незагруженная страница; дубли `broken_resource`). GET статического ресурса перезапрашивается `httpRequest()` — ответ < 400 → находки нет; сетевая ошибка без ответа — только если повторилась в 2 загрузках. Склейка одинаковых ошибок со всех страниц и ширин (запросы — по адресу без параметров). Серьёзность: свой домен `medium`, чужой `low`, консоль `low`. Причины отсева — в фактах шага (`rejected`).
- Отчёт: в находке «Где: файл, строка N, столбец M» (только `js_error`) и «Ширины: …» (`ReportBuilder.php`, `finding.php`).
- Проверено вживую, каждая находка сверена в обычном браузере: sudar.su — 4 ошибки JS, все воспроизводятся; citilink.ru — 1; cleansan.ru и wpbeginner.com — 0 (консоль в обычном браузере тоже чистая). Найдено и исключено: ошибки гидратации React #418 / #423 (страница работает — `exception_messages`), бейдж `pay.yandex.ru` с 403 (обычный браузер его не запрашивает), по находке на ширину из-за `session_id` в адресе (склейка без параметров), стек в заголовке `console.error` (только первая строка). `js-error.html` через настоящий Chrome — `Uncaught TypeError`, строка 13, столбец 32 на всех ширинах. Отчёт на 360 / 768 / 1280 px без горизонтальной прокрутки, консоль чистая; `chrome.exe` и папок профилей после диагностик нет; `app.log` без новых записей. Тесты — 661 OK на PHP 8.3 OpenServer (+ `BrowserConsoleCheckTest`, фикстуры `tests/fixtures/browser/`). Тестовые диагностики #122–#127 удалены.
- Остаётся: исключение в чужой библиотеке, вызванное кодом сайта (Panzoom на sudar.su), — `low`; у `console.error`, вызванного через Vue, место — `vue.min.js` (настоящее — в тексте и стеке); магазин на WooCommerce вживую не проверялся.
**Что следующее:** Phase 3, таск 3 «Скриншоты на трёх ширинах» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 3, таск 1 «Подключить Chrome без окна» ✅. Перед началом план Фазы 3 перепроверен (`phase-init.md`): 4 таска, ключ PageSpeed уже в `.env` — блокер снят в `_status.md`.
- Composer: `chrome-php/chrome` 1.16.2 в `require`, `config.platform.php = 8.3.6` (composer на ПК запущен PHP 8.4 — без этого выбрал бы `symfony/*` 8.x под PHP 8.4). У системного PHP 8.4 нет `sockets` — composer запускать через PHP OpenServer: `C:\OSPanel\modules\PHP-8.3\PHP\php.exe C:\Users\Mikhail\bin\composer.phar …`. `vendor/autoload.php` подключается в `config/config.php`, только если есть.
- `BrowserService.php`: запуск без окна (1366 × 768, `ignoreCertificateErrors`, свой User-Agent Chrome не подменяется), своя папка профиля `storage/temp/chrome-<id>-<случайное>/` (удаление с повторами; папки старше суток — от убитого воркера — убираются при запуске), закрытие в `finally`, версия через DevTools `Browser.getVersion` (`chrome.exe --version` на Windows молчит). Открытие страницы — свой `Page.navigate`: сетевая ошибка из `errorText` → `error` (иначе страница ошибки Chrome считалась загруженной); «открыта» — по `DOMContentLoaded` (тайм-аут 30 с), `load` ждём ещё не дольше 10 с (на cleansan.ru `load` — через 35 с из-за медленного ресурса).
- Шаг `browser` «Проверка в браузере» после `seo`: страницы — `collectResourcePages()` (главная + найденные), с защитой от ботов и без ответа не открываются, heartbeat после каждой страницы; сообщение «Открыто в браузере: 4 из 4 страниц; не открылось: …; пропущено (защита от ботов): …»; нет страниц / нет `vendor` / Chrome недоступен → `skipped` с причиной. Находок шаг пока не создаёт, результаты (`dom_ms`, `load_ms`, `error`) — в фактах.
- Настройки: карточка «Браузер» (`POST /settings/browser`, CSRF): путь к Chrome (пусто — путь по умолчанию), «Сохранить» и «Проверить Chrome» (сохранить + запустить, версия или ошибка во flash, `set_time_limit(60)`, запуск ≤ 15 с). Проверка поля — абсолютный путь Windows к `.exe` без кавычек и `<>|?*`, ≤ 255 символов.
- Проверено вживую: «Проверить Chrome» — «Chrome 157.0.8103.52 работает.» (0,7 с); три диагностики параллельно (cleansan.ru, citilink.ru, sudar.su) — «4 из 4 / 3 из 3 / 4 из 4», ни `chrome.exe`, ни папок профилей после; неверный путь → `skipped`, видно в «Не удалось проверить»; без `vendor/` сайт работает, шаг `skipped`; настройки на 360 / 768 / 1280 px, консоль чистая. Тесты — 642 OK на PHP 8.3 OpenServer (+ `BrowserServiceTest`, тесты пути в `SettingsServiceTest`). Тестовые диагностики #117–#121 удалены.
- Остаётся: при фатальной ошибке воркера (лимит времени) Chrome завершается, но папка профиля живёт до суток; пропуск страниц за защитой от ботов вживую не встретился (citilink.ru в этот раз не закрылся) — проверен тестом.
**Что следующее:** Phase 3, таск 2 «Ошибки JS и упавшие запросы» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 10 «Версия OpenCart» ✅ (добавлен по итогам таска 9). **Фаза 2 завершена.**
- Подвал входа в админку версию без входа не показывает (OpenCart 2.x / 3.x, ocStore, OPENCART.PRO — проверено вживую), поэтому версия — по отпечаткам публичных файлов ядра: `admin/view/javascript/common.js`, `admin/view/stylesheet/stylesheet.css`, `catalog/view/javascript/common.js`, `catalog/view/theme/default/stylesheet/stylesheet.css` (1.5–3.x), `catalog/view/stylesheet/stylesheet.css` (4.x).
- `VersionFingerprints.php`: md5 содержимого (без BOM, `\r\n` → `\n`) → версии релизов, пересечение по узнанным файлам; изменённый / закрытый / обрезанный файл не учитывается. `bin/build-opencart-fingerprints.php` — теги GitHub `opencart/opencart` + файлы raw.githubusercontent.com → `data/fingerprints/opencart.json` (50 релизов 1.5.5.1–4.1.0.4; сбой — прошлые отпечатки не тронуты); запускать вручную при выходе нового релиза.
- Стек: ключ `fingerprints` у платформы в `data/signatures.php` (OpenCart); `StackCheck` запрашивает файлы для найденной CMS без версии после `probes`, итог — `facts.fingerprints`. Одна версия → версия OpenCart (сильный признак); несколько → без версии, в источниках «отпечатки файлов ядра (2): 3.0.0.2–3.0.3.7 — точная версия не определена»; файлы разных релизов → без версии. `makeRoomForStackEvidence()` — источник версии (отпечатки, `probes`) вытесняет последний слабый признак, если источников уже 5 (на tehmir-shop.ru не помещался; касается и `joomla.xml`).
- Проверено вживую: на витрине opencart.com из 25 магазинов — 4 точные версии, 8 диапазонов. biog.store → «OpenCart 3.0.3.8», «устарел (актуальная 4.1.0.4)», «2 уязвимости» из OSV; NVD в это время отвечал 503 (и curl тоже) → «NVD: источник недоступен» видно вживую. tehmir-shop.ru → без версии, диапазон в источниках. Консоль чистая, `app.log` не создан, тестовые диагностики #112–#116 удалены. Тесты — 619 OK на PHP 8.3 OpenServer (+ `VersionFingerprintsTest`, тесты отпечатков в `StackCheckTest`, настоящие файлы 3.0.3.8 в `tests/fixtures/stack/opencart-3.0.3.8/`).
- Остаётся: OpenCart в подпапке (`/shop/`) версию не получит (файлы — от корня); диапазон версий не используется в `outdated` / `vulnerability` (можно брать только то, что верно для всех версий диапазона); отпечатки не обновляются еженедельно.
**Что следующее:** Фаза 3 «Проверка в браузере» — перепроверить план промптом `.docs/prompts/phase-init.md` с учётом сделанного (открытый вопрос: ключ PageSpeed Insights — к таску 4).
**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 9 «Уязвимости ядер CMS: NVD + OSV» ✅.
- `HttpClient.php`: `httpApiRequest()` — запросы к фиксированным внешним API (GET или POST JSON, только https, тайм-аут 30 с, свой предел ответа 20 МБ: ответ NVD по Magento — около 4 МБ, предел `httpRequest()` — 5 МБ; `httpRequest()` к тому же не умеет POST, а OSV `/v1/query` — только POST).
- `NvdClient.php`: CPE из шаблона (`{version}`, `{update}` — патч Magento `2.4.6-p3` → `2.4.6:p3`, без патча `-`), `virtualMatchString` без API-ключа (решение владельца: 1 CMS на диагностику — лимита 5 / 30 с хватает), разбор `configurations` → диапазоны только своего vendor:product, `versionEndExcluding` → исправление, CVSS v4 → v3.1 → v3.0 → v2 (оценка NVD важнее), ссылка на nvd.nist.gov. `OsvClient.php`: `POST api.osv.dev/v1/query` (Packagist), события `introduced` / `fixed` / `last_affected`, диапазоны `GIT` не берутся, CVE из `aliases` — первыми, серьёзность GitHub Advisory (`MODERATE` → medium).
- `VulnerabilityService.php`: `mergeVulnerabilitiesByCve()` — одна CVE из NVD и OSV → одна запись (ids, диапазоны, исправления, ссылки `urls` — объединяются, CVSS — NVD, описание — короче); `shortenVulnerabilityTitle()` — целые предложения до 160 символов («Joomla!» — не конец предложения).
- `VulnerabilityCheck.php`: ветка ядер CMS (`nvd_cpe` / `osv` в `data/version-sources.php`: Joomla, Magento — `magento:magento` + `adobe:magento` open_source, PrestaShop, OpenCart). Кэш `storage/cache/vulnerabilities/{nvd|osv}-<cms>-<версия>.json` на 7 дней (пустой ответ тоже), сбой — старый кэш любой давности («NVD: источник недоступен, ответ из кэша от …») или «источник недоступен»; ошибка в кэш не пишется, второй источник работает. Версия короче трёх частей (`2.4` из заголовка Magento) — «версия неточная — уязвимости ядра не сверялись». Источники — в `evidence.sources`. `DiagnosticRunner.php` менять не понадобилось.
- Проверено вживую: joomline.ru (Joomla 3.10.12 из `joomla.xml`; магазина на Joomla / OpenCart с открытой версией не нашлось) — одна находка «38 уязвимостей», «обновить до 5.4.8»; повторная диагностика — без запросов (файлы кэша не изменились); консоль чистая, `app.log` не создан. Тесты — 603 OK на PHP 8.3 OpenServer (+ `NvdClientTest`, `OsvClientTest`, настоящие ответы NVD / OSV по OpenCart 3.0.3.8 и Joomla 4.2.7 в `tests/fixtures/vulnerabilities/`).
- Найдено: версию OpenCart стек не определяет (у сигнатуры нет источника версии) — ядро OpenCart на живых сайтах не сверяется → таск 10. Старая неточность «исправлено в» из другой ветки (CVE-2024-21726 для 3.10.12 → 4.4.3) — логика таска 7, не трогалась.
**Что следующее:** Phase 2, таск 10 «Версия OpenCart» (добавлен по итогам таска 9).
**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 8 «Точность версий и меньше ложных находок» ✅ (добавлен по итогам проверки таска 7). Перед началом — коммит таска 7 (`9cffc9c`).
- `StackDetector.php`: версии ассетов (тема / плагин / библиотека) — голосованием, а не первый файл. Точные версии (meta generator, служебный файл, заголовок) — как раньше. Файлы сторонних библиотек темы / плагина (`/lib/`, `/vendor/`, `/node_modules/` …, сигнатуры `libraries`, `jquery.*.js`) не голосуют; файл с именем плагина (`wpforms.min.js`) — вес 2, остальные — 1; ничья разных версий → версия неизвестна. Библиотека: версия в имени / пути — вес 2, `?ver=` — 1, а в папке темы / плагина не учитывается (это версия темы).
- `VulnerabilityService.php`: роль из названия записи Wordfence — Administrator+ / Editor+ / Shop Manager+ / Super Admin → не выше `low`, Author+ / Contributor+ → ступенью ниже, Subscriber+ / Customer+ без изменений (покупатели регистрируются); записи «End-of-Life / no longer supported» — не уязвимость (это `outdated`); склонение «уязвимость».
- `VulnerabilityCheck.php`: элементы стека с уверенностью `low` и темы с общими именами (`main`, `theme`, `shop` …) не сверяются; одна находка на элемент стека — «perfmatters 2.5.3: 5 уязвимостей (CVE-…)», серьёзность наибольшая, «Обновить до» — наибольшая из исправленных, каждая уязвимость (идентификаторы, диапазон, исправление, CVSS, роль, ссылка) — в тексте и `evidence.vulnerabilities`; сообщение шага считает уязвимости, а не находки.
- Проверено вживую: wpbeginner.com — wpforms 2.0.3 (было 1.21.0), ложная уязвимость ушла, одна находка perfmatters (5 уязвимостей, high); cleansan.ru — jQuery 2.2.4: одна находка вместо шести (EOL-дубль убран); sudar.su — без изменений (`info` по модулю vote). Тесты — 575 OK на PHP 8.3 OpenServer (+ фикстура `tests/fixtures/stack/wordpress-versions.html`, тесты голосования версий, ролей, End-of-Life, группировки); старые тесты стека — без правок ожиданий. Тестовые диагностики удалены, `app.log` пуст.
- Остаётся: плагин с постоянным `?ver=1.0` во всех файлах неотличим от настоящей версии; строже правила — чаще версия не определяется (нет и находки).
**Что следующее:** Phase 2, таск 9 «Уязвимости ядер CMS: NVD + OSV» — создать TASK.md.

**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 7 «Уязвимости: локальные базы» ✅.
- Базы: `bin/update-databases.php` (реестр `getDatabaseSources()`) + Wordfence Intelligence v3 `production` — вся база одним запросом (лимит 1 / 30 мин; в `scanner` нет CVE и CVSS), скачивается сразу в файл `httpDownloadToFile()` (`HttpClient.php`: `Authorization: Bearer`, свой тайм-аут и предел, без лимита 5 МБ) и разбирается потоково `iterateJsonObjectMembers()` (3 с, 92 МБ на 88 МБ фида) → `storage/databases/wordfence-vulnerabilities.json` (индекс `core|plugin|theme:slug`, 47 384 записи, CVSS у всех, CVE у 93 %); retire.js `jsrepository-v4.json` → `retire-vulnerabilities.json`. Нет токена / 429 / 401 / битый файл — прошлая база не тронута. Дата базы без дат у записей — общая `updated_at`.
- `VulnerabilityService.php`: диапазоны с включением / исключением границ, CVSS → серьёзность (≥ 9 critical, ≥ 7 high, ≥ 4 medium, иначе low; у retire.js — его severity), ближайшая исправленная версия, диапазон по-русски. Единый формат записи для всех баз (`ids`, `title`, `ranges`, `fixed`, `cvss`, `severity`, `url`, `source`).
- Шаг `vulnerabilities` «Уязвимости» (после `outdated`): `Checks/VulnerabilityCheck.php`; WordPress ядро / плагины / темы → Wordfence, JS-библиотеки → retire.js (ключ `retire` в `data/version-sources.php`), Битрикс → `data/vulnerabilities/bitrix.json` (собран из NVD: 4 записи ядра, модули vote — из поставки, mcart.xls, orion.extfeedbackform — по пространству имён компонентов; БДУ ФСТЭК с ПК недоступна — сертификат российского УЦ). Без версии находки нет; модуль Битрикса → `info` «проверить версию». Shop-Script — «нет источника данных», Мегагрупп / InSales / Tilda — «платформа обновляется провайдером», нет базы — «база уязвимостей не загружена» (в сообщении шага). Базы читаются, только если есть что сверять; их даты — в `summary_json.databases` (список в карточке «Стек»).
- Настройки и `.env.example`: `WORDFENCE_API_KEY` вместо `WPSCAN_API_KEY`.
- Проверено вживую: обновление — 3 базы за 50 с; sudar.su — только `info` по модулю vote (CVE-2022-27228); cleansan.ru — jQuery 2.2.4: 6 находок retire.js; wpbeginner.com — 6 уязвимостей WordPress-плагинов, из них wpforms — ложная: версия взята с встроенной библиотеки (`jquery.validate.min.js?ver=1.21.0`, настоящая — 2.0.3) → отдельный таск 8. Экран — 360 px, консоль чистая. Тесты — 566 OK на PHP 8.3 OpenServer (+ `VulnerabilityServiceTest`, `VulnerabilityDatabasesTest`, `VulnerabilityCheckTest`, фикстуры `tests/fixtures/vulnerabilities/`). Тестовые диагностики удалены, `app.log` пуст.
**Что следующее:** Phase 2, таск 8 «Точность версий и меньше ложных находок» (добавлен по итогам проверки таска 7; NVD + OSV стал таском 9).

**Дата:** 16.11.2026
**Что сделано:** Phase 2, таск 6 «Устаревшие версии и еженедельное обновление баз» ✅. Перед началом — коммит таска 5 (`411615e`); токен Wordfence владелец положил в `.env`, блокер таска 7 снят в `_status.md` и `phase-2.md`.
- Справочник версий: `bin/update-databases.php` → `DatabaseUpdater.php` (реестр баз `getDatabaseSources()`, запись во временный файл и замена, lock через `flock`, чтение рабочей базы `storage/databases/` или начальной `data/latest-versions.json`, дата базы — самая старая из дат записей) + `VersionSources.php` (npm `/latest`, Packagist p2, GitHub releases без пред-релизов и черновиков — максимум, а не первый; ядро WordPress; php.net `active.php` — ветки PHP). Упавший источник оставляет прошлые данные. Итог — `storage/logs/update-databases.log` (в `app.log` успешные запуски не пишутся: `APP_LOG_LEVEL=warning`), сбои — ещё и в `app.log`.
- `data/version-sources.php`: ключ стека → источник, пакет / репозиторий, `eol_below` (jQuery < 3, Bootstrap < 5, Vue < 3, Lodash < 4, Joomla < 5, Magento < 2.4, PrestaShop < 1.7); у PHP ветки без поддержки — по php.net. `VersionService.php`: нормализация (`v1.2`, `1.12.4-aem`, `2.4.7-p3`; хэш и метка времени — не версия), стабильность, сравнение, ветки.
- Шаг `outdated` «Устаревшие версии» (после `stack`): `OutdatedCheck.php`, серьёзности — таблица `OUTDATED_SEVERITY` (ядро CMS `medium` / без поддержки `high`, PHP `low` / `high`, JS `low` / `medium`, плагин WordPress `medium`). Плагины WordPress — каталог api.wordpress.org, кэш 7 дней в `storage/cache/wp-plugins/` («нет в каталоге» тоже, сбой сети — нет). Версия новее справочника — без находки и без `latest_version`. `runStackStep()` кладёт id `stack_items` в факты; `StackModel::updateStackItemLatestVersion()`; дата базы — `summary_json.databases`.
- Отчёт: у элемента стека «актуальная: X» и бейдж «устарела» / «ветка не поддерживается» (по находке с `evidence.stack_item_id`); внизу карточки «Стек» — список баз с датами, база старше 14 дней на момент диагностики → предупреждение о Планировщике.
- `bin/schedule-update-databases.ps1` (UTF-8 с BOM для PowerShell 5.1): задача «Site Diagnostics - обновление баз» — понедельник 10:00, пропущенный запуск при включении ПК; зарегистрирована, повторный запуск — одна задача, ручной запуск — результат 0, дата базы обновилась.
- Вне Scope: `StackDetector` — у JS-библиотек не было `key` (jQuery на cleansan.ru не сравнивался), одна строка + тест.
- Проверено вживую: первый запуск — 16 источников без ошибок (Magento 2.4.9 — пред-релиз `2.4.10-alpha1` отброшен); cleansan.ru — jQuery 2.2.4 «ветка не поддерживается»; wordpress.org/news — jetpack 16.3 «устарел» (16.4); sudar.su, citilink.ru — версии не видны. Экран — 360 / 768 / 1280 px, консоль чистая. Тесты — 524 OK на PHP 8.3 OpenServer (+ `VersionServiceTest`, `VersionSourcesTest`, `DatabaseUpdaterTest`, `OutdatedCheckTest`, фикстуры `tests/fixtures/versions/`). Тестовые диагностики удалены, `app.log` пуст.
**Что следующее:** Phase 2, таск 7 «Уязвимости: локальные базы» — создать TASK.md.

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
