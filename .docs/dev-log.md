# Dev Log

Журнал рабочих сессий. Свежие записи — сверху, сразу под этим блоком.

---

## Формат записи

**Дата:** ДД.ММ.ГГГГ
**Что сделано:** ...
**Что следующее:** ...

---

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
