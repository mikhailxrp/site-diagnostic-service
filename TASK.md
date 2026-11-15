# Current Task

## Фаза
Phase 1 — Экспресс-диагностика

## Задача
У шагов диагностики есть общий контекст (диагностика + собранные факты), шаг может остановить проверку; есть
HTTP-клиент с безопасными редиректами и запись находок и страниц в БД — основа для тасков 3–5.

## Образец
Интерфейса в таске нет — образец не нужен.

## Scope — что трогаем
- [x] `src/Services/DiagnosticRunner.php` — изменить:
      - контекст: `['diagnostic' => ['id', 'url', 'host', 'problem_text'], 'facts' => []]`;
      - обработчик `handler(array $context): array` → `status`, `message`, необязательно `facts` (runner кладёт
        в `$context['facts'][<код шага>]`), `findings` (список из `FindingFactory`, runner записывает через
        `FindingModel`), `stop` (bool);
      - `stop = true` → оставшиеся шаги `skipped` с причиной «Пропущено: <title шага> остановил проверку»,
        прогресс считается и для них; `onStepStart` для пропущенных не вызывается;
      - упавший шаг (`failed`) проверку не останавливает (как сейчас); его `facts` / `findings` не принимаются;
      - `executeSteps()` остаётся без БД: контекст и запись находок — через параметры/колбэки (тестируемо);
      - убрать `$stub`, `sleep`, `STUB_STEP_DELAY_SECONDS` и шаг `report`. Шаги `availability`, `stack`,
        `resources` до тасков 3–5 возвращают `skipped` «Проверка появится в следующей версии»
- [x] `src/Services/HttpClient.php` — создать (функции, пределы — именованные константы):
      - `resolveRedirectUrl(string $base, string $location): ?string` — относительный → полный адрес
        (`/path`, `path`, `../x`, `//host/x`, `?q`); не http(s) → `null`;
      - `httpRequest(string $url, string $method = 'GET', ?callable $resolver = null, ?callable $transport = null): array` —
        ручное следование редиректам (`HTTP_MAX_REDIRECTS = 10`), перед **каждым** запросом (и первым) хост
        проверяется через `validateSiteHost()` из `UrlValidator`. Результат: `final_url`, `status`, `headers`,
        `body`, `truncated`, `redirects` (`[{url, status, location}]`), `time_ms`, `error`;
      - `transport` — один запрос curl без авто-редиректов (`CURLOPT_FOLLOWLOCATION = false`); в тестах —
        заглушка без сети;
      - curl: `CURLOPT_PROTOCOLS` только http/https; `CURLOPT_RESOLVE` на уже проверенный IP (защита от подмены
        DNS между проверкой и запросом); `HTTP_CONNECT_TIMEOUT_SECONDS = 10`, `HTTP_TIMEOUT_SECONDS = 30`;
        `HTTP_MAX_BODY_BYTES = 5 МБ` — при превышении загрузка обрывается, тело — первая часть,
        `truncated = true`; `User-Agent: SiteDiagnostics/1.0 (site audit)`; проверка SSL включена, ошибка
        сертификата — в `error`, без исключения;
      - паузы между запросами нет (решение владельца)
- [x] `src/Services/FindingFactory.php` — создать:
      - `FINDING_DEFAULT_SEVERITY` — серьёзность по категории (категории из `database.md`);
      - `makeFinding(category, title, location, key, details, evidence, ?pageId, ?severity)` — неизвестная
        категория или серьёзность → `InvalidArgumentException`;
      - `buildFingerprint(category, location, key)` — sha1; адрес нормализуется (схема и хост в нижний регистр,
        без `#фрагмента`)
- [x] `src/Models/FindingModel.php` — создать: `insertFinding(int $diagnosticId, array $finding): int`
- [x] `src/Models/PageModel.php` — создать: `insertCheckedPage(int $diagnosticId, array $page): int`
      (вызывается с таска 3)
- [x] `database/install.php` — изменить: таблицы `checked_pages` и `findings` по `database.md` (индексы, FK)
- [x] `.docs/database.md` — изменить: пометка, что `findings.ai_result_id` пока без FK — таблица `ai_results`
      появляется в фазе 4, FK добавляется там же (`ALTER`)
- [x] `tests/Unit/DiagnosticRunnerTest.php` — дополнить: остановка → остальные `skipped` с причиной и
      прогресс 100; факты шага видны следующему; `findings` передаются на запись, у упавшего шага — нет;
      шага `report` нет
- [x] `tests/Unit/HttpClientTest.php` — создать: относительные `Location`, цепочка редиректов, обрыв на
      редиректе к `127.0.0.1` / `10.x` / `localhost` / `ftp://`, предел редиректов, предел размера
- [x] `tests/Unit/FindingFactoryTest.php` — создать: серьёзность по категории, переопределение, отказ на
      неизвестную категорию, `fingerprint` стабилен и не зависит от регистра хоста и `#фрагмента`

## Out of scope — не трогаем
- Настоящие проверки: доступность/SSL (таск 3), стек (таск 4), ресурсы (таск 5); запись `summary_json` — таск 3
- Таблица `stack_items` и `StackModel` — таск 4
- Функции чтения находок и страниц, отчёт на экране — таск 6
- Категория `mixed_content` — таск 5, вместе с проверкой
- Обход защиты от ботов — фаза 2
- `bin/worker.php`, `JobLauncher.php`, `DiagnosticStatusService.php`, контроллеры, Views, JS (экран прогресса
  сам покажет 3 шага — берёт их из `getDiagnosticSteps()`)
- `src/Core/*`, рефакторинг существующего кода

## Definition of Done
- [x] Шаг вернул `stop` → все следующие шаги `skipped` с причиной, диагностика `done`, прогресс 100
      (тест + вручную в БД)
- [x] Факты шага доступны следующему шагу; находки шага записываются, у упавшего — нет (тест)
- [x] Относительный `Location` правильно превращается в полный адрес (тест)
- [x] Редирект на частный IP / `localhost` / не http(s) обрывается с понятной ошибкой в `error`, запроса туда
      нет (тест с заглушкой)
- [x] Больше 10 редиректов → ошибка, без зацикливания (тест)
- [x] Ответ больше 5 МБ обрывается с `truncated = true`, воркер не падает по памяти (тест + вручную на большом
      файле)
- [x] Зависший сервер обрывается по тайм-ауту, без исключения
- [x] `fingerprint` одинаков для одинаковой находки и разный для разных (тест)
- [x] `php database/install.php` создаёт `checked_pages` и `findings`, повторный запуск — «уже есть»
- [x] Новая диагностика `sudar.su` проходит до `done` без задержек; на экране прогресса 3 шага, «Сборки отчёта»
      нет
- [x] `composer test` проходит, `app.log` без ошибок; расширение `curl` в PHP CLI включено
- [x] Проверить `.docs/dod-global.md` (не применяется: UI-формы, вход, роли, `user_id`)

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
