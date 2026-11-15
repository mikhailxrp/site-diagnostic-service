# Dev Log

Журнал рабочих сессий. Свежие записи — сверху, сразу под этим блоком.

---

## Формат записи

**Дата:** ДД.ММ.ГГГГ
**Что сделано:** ...
**Что следующее:** ...

---

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
