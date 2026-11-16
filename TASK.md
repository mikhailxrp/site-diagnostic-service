# Current Task

## Фаза
Phase 5 — Для клиента и углублённые проверки (таск 1 — Открытые служебные файлы) — ✅ Завершён

## Задача
Шаг `exposed_files` (после `seo`) проверяет фиксированный список служебных путей и даёт находку `exposed_file`,
только если по содержимому видно, что это настоящий файл, а не заглушка или «мягкий 404».

## Образец
Без UI. Находки выводит существующий компонент `src/Views/components/finding.php` в разделе «Открытый файл»
(категория `exposed_file` уже есть в `FindingFactory.php` и `ReportBuilder.php`). Правки интерфейса не нужны.

## Как это работает
1. **Нет данных главной** (шаг `availability` не дал ответа) → `skipped` с причиной.
2. **Эталон «мягкого 404»**: запрашивается случайный несуществующий адрес (`/sd-<16 hex>.txt`).
   - Если эталон — заглушка защиты от ботов (`detectBotProtection()`), то `skipped`: «сайт закрыт защитой
     от ботов, служебные файлы не проверить».
   - Если эталон отвечает 200, запоминается sha1 его тела.
3. **Каждый путь из `data/exposed-files.php`** — GET с пределом `EXPOSED_FILE_MAX_BYTES`
   (`Range: bytes=0-N`, обрыв загрузки в curl, без сжатия, потому что Range режет сжатый поток).
   Файл считается открытым, только если выполнены все условия:
   - статус 200 или 206;
   - адрес после редиректов не сменил путь;
   - тело не совпадает с эталоном;
   - сработал признак содержимого пути.
4. **Признаки** (имя признака уходит в `evidence`):
   - `git_head`: строка начинается с `ref: refs/` или 40 hex;
   - `git_config`: `[core]`;
   - `env`: строки `KEY=` в начале строки, тело не HTML;
   - `php_source`: начинается с `<?php`, есть `DB_`/`password` (бэкапы конфигов);
   - `sql_dump`: `-- MySQL dump`, `-- phpMyAdmin SQL Dump`, `CREATE TABLE`, `INSERT INTO`;
   - `archive`: magic bytes zip `PK\x03\x04`, gzip `\x1f\x8b`, rar `Rar!`, 7z;
   - `phpinfo`: `<title>phpinfo()</title>` или `PHP Version` + `phpinfo`;
   - `adminer`: `Adminer` + форма входа;
   - `php_log`: `PHP Fatal error` / `PHP Warning` / `PHP Notice` / `[error]`.
5. **Серьёзность**:
   - `critical`: `.git`, `.env`, бэкапы конфигов, дампы, архивы;
   - `high`: phpinfo, adminer;
   - `medium`: логи.
6. **Секреты никуда не пишутся.** В `evidence` попадают только `status`, `bytes_read`, `content_type`
   и `signature` (имя признака). Тело ответа не сохраняется ни в находке, ни в `details`, ни в логе.
   `details` — шаблонный текст «чем опасно и что сделать».
7. **Heartbeat** — после каждого запроса (колбэк, как у `resources`).
8. **Сообщение шага**: «Проверено N путей, открыто M».

## Scope — что трогаем
- [ ] `data/exposed-files.php` — создать: список `path → signature, severity, title`
      (`.git/HEAD`, `.git/config`, `.env`, `.env.bak`, `wp-config.php.bak`, `wp-config.php~`, `wp-config.php.old`,
      `configuration.php.bak`, `config.php.bak`, `backup.sql`, `dump.sql`, `db.sql`, `database.sql`,
      `backup.zip`, `backup.tar.gz`, `site.zip`, `phpinfo.php`, `info.php`, `adminer.php`, `error_log`,
      `wp-content/debug.log`) и описание признаков
- [ ] `src/Services/Checks/ExposedFilesCheck.php` — создать: `runExposedFilesCheck($context, $request, $onRequest,
      ?$rules, ?$botRules)` без БД и сети; эталон, сравнение, признаки, находки через `makeFinding('exposed_file', …)`
- [ ] `src/Services/HttpClient.php` — изменить: необязательный предел размера ответа в `httpRequest()`, который
      передаётся в `sendCurlRequest()`. При пределе: заголовок `Range`, `Accept-Encoding: identity`, обрыв по
      `limitBodyChunk()`. Без параметра поведение прежнее.
- [ ] `src/Services/DiagnosticRunner.php` — изменить: `require_once` проверки, шаг
      `['code' => 'exposed_files', 'title' => 'Служебные файлы', …]` после `seo`, обработчик `runExposedFilesStep()`
      (реальная сеть + `touchDiagnosticHeartbeat`)
- [ ] `tests/Unit/ExposedFilesCheckTest.php` — создать
- [ ] `tests/Unit/HttpClientTest.php` — изменить: предел передаётся в transport, без него поведение прежнее
- [ ] `tests/Unit/DiagnosticRunnerTest.php` — изменить: `testStepsOrder` с `exposed_files` после `seo`

## Out of scope — не трогаем
- Отдельная карточка или таблица «Служебные файлы» в отчёте, `summary_json` для этого шага
- Пути, зависящие от CMS или домена (`<домен>.zip` и т. п.): только фиксированный список
- Отдельный тайм-аут для шага: используется общий `HTTP_TIMEOUT_SECONDS`
- Листинг каталогов, перебор `.git/objects`, скачивание файлов целиком
- `ReportBuilder.php`, `FactPacker.php`, Views: находки идут по общему пути
- Таски 2–7 фазы 5 (сценарий корзины, клиентский отчёт, PDF, сравнение)
- Рефакторинг `SeoCheck` и других проверок

## Definition of Done
- [ ] Сайт, который на любой адрес отдаёт 200 с главной, не даёт ни одной находки (тест)
- [ ] Заглушка с правильным путём, но без признака (HTML-страница на `/.env`), не даёт находки (тест)
- [ ] Открытый `.env` и `.git/HEAD` дают находки `critical` (тест)
- [ ] В `evidence`, `details`, отчёте и `storage/logs/app.log` нет значений из файла: только факт доступности
      и имя признака (тест на `evidence` + ручная проверка лога)
- [ ] В transport уходит предел `EXPOSED_FILE_MAX_BYTES`, тело в результате не длиннее его (тест)
- [ ] Сайт за защитой от ботов → шаг `skipped` с причиной, находок нет (тест)
- [ ] Нет главной → `skipped` (тест)
- [ ] Шаг виден в прогрессе после «Базовое SEO», старые диагностики без шага открываются без ошибок
- [ ] Проверено вживую на sudar.su, cleansan.ru, citilink.ru: ложных находок нет, время шага в сообщении разумное
- [ ] `composer test` проходит
- [ ] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
