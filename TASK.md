# Current Task

## Фаза
Phase 0 — Фундамент

## Задача
Временной кнопкой с главной запускается диагностика-заглушка. На её странице `/diagnostics/{id}` общий прогресс
и статусы шагов обновляются без перезагрузки, а по завершении виден итог и место под отчёт.

## Образец
Образца нет: экран собирается из стандартных компонентов Tabler без своих стилей (решение владельца от 15.11.2026).
Используются `page-header`, `card`, `<progress class="progress">`, `list-group`, `badge`, `alert`, `empty`.
Состояния экрана: **в очереди** (шагов в БД ещё нет, показываются плановые шаги со статусом «ожидает»), **идёт**,
**готово** (с местом под отчёт), **ошибка** (`error_message`), **не найдена** (404).

## Scope — что трогаем
- [x] `config/routes.php` — изменить: добавить `GET /diagnostics/{id}`, `GET /diagnostics/{id}/status`,
      `POST /diagnostics/start`
- [x] `src/Services/DiagnosticStatusService.php` — создать (чистые функции без БД):
      - `parseDiagnosticId(string $raw): ?int` — только `^[1-9][0-9]{0,9}$`, как в воркере;
      - `buildDiagnosticStatus(array $diagnostic, array $plannedSteps): array` — данные и для View, и для JSON:
        `id`, `status`, `statusLabel`, `progress`, `errorMessage`, `isFinished`, `steps[]` (`code`, `title`,
        `status`, `statusLabel`, `badgeClass`, `message`). Если шагов в БД нет, берутся плановые из
        `getDiagnosticSteps()` со статусом `pending`;
      - константы-словари: подписи статусов и классы бейджей Tabler. Подписи живут в одном месте,
        JS берёт их из JSON
- [x] `src/Controllers/DiagnosticController.php` — создать:
      - `show(string $id)`: id → `refreshStaleDiagnostic()` → `getDiagnosticWithSteps()` →
        `render('pages/diagnostic')`. Неверный или несуществующий id → код 404 и состояние «не найдена»;
      - `status(string $id)`: JSON (`Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`),
        перед ответом тоже `refreshStaleDiagnostic()`. Неверный id или нет записи → 404 `{"error":"not_found"}`.
        Ошибка БД → `logException()` и 500 `{"error":"server_error"}`;
      - `start()` — **временная**: `requireCsrf()` → `createDiagnostic()` с константой-заглушкой URL
        (`https://example.com`, host `example.com`) → `launchWorker()`. Если воркер не запустился:
        `finishDiagnostic(failed, …)`, `logException()`, флеш с ошибкой. В конце всегда
        `redirect('/diagnostics/{id}')`; если упал уже `createDiagnostic()` — флеш и `redirect('/')`
- [x] `src/Views/pages/diagnostic.php` — создать (путь по аналогии с `pages/settings.php`, а не
      `diagnostics/show.php` из плана фазы): заголовок с URL, бейдж статуса, `<progress class="progress" value max>`
      и список шагов с бейджами. Для `failed` — `alert-danger` с `error_message`. Для `done` — карточка
      «Отчёт появится в Фазе 1». Для «не найдена» — блок `empty` со ссылкой на главную. Внизу
      `<script type="module">` со ссылкой на JS, в `data-*` лежат id и URL статуса
- [x] `public/assets/js/diagnostic-progress.js` — создать: ES-модуль без глобальных переменных. Опрос через
      `setTimeout` раз в 2 с (следующий запрос только после ответа на предыдущий). Обновляет `progress.value`,
      `aria-valuenow`, бейджи и сообщения шагов через `textContent`. На `done`/`failed` останавливается и
      показывает итоговый блок. Ошибка сети — ненавязчивое предупреждение и повтор, 404 — остановка
- [x] `src/Views/pages/home.php` — изменить: в карточку-заглушку добавить временную форму `POST /diagnostics/start`
      с `csrfField()` и кнопкой «Тестовый запуск (заглушка)»
- [x] `tests/Unit/DiagnosticStatusServiceTest.php` — создать: `parseDiagnosticId()` (пусто, `0`, `abc`, `-5`, `1a`,
      11 цифр, валидный), плановые шаги в `queued`, подписи и бейджи для всех статусов, `isFinished`
      для `done`/`failed`

## Out of scope — не трогаем
- Настоящая форма «Новая диагностика» (URL и жалоба), нормализация URL, `host`, `previous_id` — фаза 1
- Содержимое отчёта (стек, находки) — фаза 1; здесь только место под него
- Страница «История» `/history` — фаза 2
- Отмена диагностики (`cancelled`), повторный запуск, удаление
- `src/Models/*`, `src/Services/DiagnosticRunner.php`, `JobLauncher.php`, `bin/worker.php`: используются как есть
- Общий макет `src/Views/admin/layout/*`, `src/Core/*` (в том числе общий 404 в `dispatch()`), `admin-theme.css`
- Свои CSS-стили
- Рефакторинг существующего кода

## Definition of Done
- [x] Кнопка «Тестовый запуск» на главной сразу переводит на `/diagnostics/{id}`. Если закрыть вкладку, воркер
      всё равно доводит диагностику до `done` (проверка в БД; перенесено из таска 5)
- [x] Прогресс-бар и бейджи шагов обновляются без перезагрузки (4 шага по 2 с: 0 → 25 → 50 → 75 → 100)
- [x] Состояние «в очереди» показывает плановые шаги со статусом «ожидает»
- [x] На `done`/`failed` опрос прекращается (на вкладке Network новых запросов нет), страница показывает итог;
      у `done` есть карточка под отчёт
- [x] `failed` (например, после неверного `PHP_CLI_PATH`) показывает `alert` с причиной и флеш с ошибкой
- [x] Если открыть страницу диагностики, у которой heartbeat старше 10 минут, она становится `failed`
      «Диагностика прервана»
- [x] `/diagnostics/abc`, `/diagnostics/0`, `/diagnostics/999999` → 404 (страница «не найдена»);
      `/diagnostics/abc/status` и `/diagnostics/999999/status` → 404 JSON
- [x] `POST /diagnostics/start` без CSRF-токена → 403 (было 419, см. отчёт), записи в БД нет
- [x] Экран соответствует решению «стандартные компоненты Tabler» (образца нет): все состояния реализованы
      (в очереди, идёт, готово, ошибка, не найдена); нет своих стилей, inline-стилей и `<style>`
- [x] Всё выводится через `e()` (в JS — через `textContent`)
- [x] Экран читается на 360 / 768 / 1280 px, зоны нажатия ≥ 44 px; ошибок в консоли нет; `app.log` чистый
- [x] `composer test` проходит, включая `DiagnosticStatusServiceTest`
- [x] Проверить `.docs/dod-global.md`. Применимо: UI, CSRF, вывод через `e()`, код, логи. Не применимо: вход,
      роли, `user_id`, деньги, загрузка файлов. Rate-limit не нужен: форма не публичная, сайт отвечает только
      с этого ПК

## Решения
- Прогресс-бар — нативный `<progress class="progress">` (Tabler его стилизует): `.progress-bar` требует
  `style="width:…"`, а inline-стили запрещены
- Шаблон — `pages/diagnostic.php` по аналогии с настройками (в плане фазы — `diagnostics/show.php`)
- Временная кнопка — на главной, фиксированный `https://example.com`; шаги — заглушки, сайт они не запрашивают
- Подписи и цвета статусов задаются в PHP-сервисе, JS получает их в JSON — без дублирования
- `<script>` подключается в самом шаблоне, футер макета не меняется

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
- Временные скрипты проверки — только в scratchpad; тестовые диагностики после проверки удалить

## Отчёт
- `DiagnosticStatusService.php`: `parseDiagnosticId()`, `buildDiagnosticStatus()`, `buildStepStatus()`, словари подписей
  и бейджей. Пока воркер не создал шаги в БД, показываются плановые шаги из `getDiagnosticSteps()` со статусом `pending`.
- `DiagnosticController.php`: `show` / `status` перед ответом вызывают `refreshStaleDiagnostic()`. Неверный или
  несуществующий id → 404 (страница `empty` / JSON `not_found`); ошибка БД → лог и 500. `start` — временный.
  Если воркер не запустился: диагностика → `failed` «Не удалось запустить воркер: …», лог с `diagnostic_id`, флеш.
- `pages/diagnostic.php`: состояния «в очереди», «идёт», «готово» (карточка «Отчёт»), «ошибка» (`alert-danger`),
  «не найдена». Нативный `<progress class="progress">`, своих и inline-стилей нет.
- `diagnostic-progress.js`: опрос раз в 2 с через `setTimeout` после ответа, вывод через `textContent`. На `done`/`failed`
  опрос останавливается, на 404 — тоже (с предупреждением); при ошибке сети — предупреждение и повтор.
- Проверено в браузере (Playwright): запуск → `/diagnostics/1`, прогресс 25 → 50 → 75 → 100 без перезагрузки,
  5 запросов статуса, после `done` новых нет; консоль чистая. Диагностика №2 (вкладку закрыли сразу после запуска)
  дошла до `done` — проверено в БД. «В очереди» показывает 4 шага «Ожидает». `running` с heartbeat 11 мин назад →
  «Диагностика прервана», зависший шаг → `failed`. Неверный `PHP_CLI_PATH` (временно в `.env`, потом восстановлен) →
  флеш + `alert` с причиной + запись в `app.log`. `/diagnostics/abc`, `/0`, `/999999` → 404; `/abc/status`,
  `/999999/status` → 404 JSON. Экран — 360 / 768 / 1280 px.
- `composer test` — 84 теста OK (+22: `DiagnosticStatusServiceTest`).
- Тестовые записи удалены, `AUTO_INCREMENT` сброшен, тестовый `app.log` и скриншоты удалены.
- **Правка вне scope (по просьбе владельца):** запрос без CSRF отдавал HTTP 500 с текстом «419 …» — Apache
  (OpenServer) подменяет нестандартный код 419 на 500. `requireCsrf()` в `src/Core/functions.php` теперь отвечает
  **403**; проверено curl для `/diagnostics/start` и `/settings`, записей в БД нет.
- **Правка вне scope (по просьбе владельца):** `.phpunit.result.cache` добавлен в `.gitignore`.
- Решение: шаблон — `pages/diagnostic.php` вместо `diagnostics/show.php` из плана фазы.
