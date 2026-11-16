# Current Task

## Фаза
Phase 4 — ИИ-анализ

## Задача
Сервис отправляет запросы в OpenAI-совместимый API RouterAI и умеет сохранять ответы в `ai_results`; в настройках
модели проверяются по списку RouterAI, по умолчанию — `qwen/qwen4-max`; кнопка «Проверить подключение» показывает
ответ модели или ошибку API.

## Образец
Образца нет: экран из компонентов Tabler (решение владельца, 15.11.2026). Своего CSS и JS нет.
- **Где:** карточка «Модели ИИ (RouterAI)» на `/settings`.
- **Поля:** те же три; если модель не сохранена, в поле подставляется `qwen/qwen4-max`. Плашка «Модели ещё не
  заданы» меняется на `alert alert-info` «Используются модели по умолчанию — сохраните, чтобы закрепить».
- **Под каждым полем** (`form-hint`, к нынешней подсказке) — строка о модели из списка RouterAI: «Qwen: Qwen4 Max ·
  картинки: да · 160 / 481 ₽ за 1 млн токенов». Модели нет в списке или список недоступен — строки нет.
- **Кнопки в футере:** «Проверить подключение» (`btn btn-outline-primary btn-lg`, `name="action" value="check"` —
  проверить поля, сохранить, отправить тестовый запрос) и «Сохранить».
- **Состояния:**
  - успех проверки — flash `success`: «Подключение работает: qwen/qwen4-max, 3,2 с, токены 25 / 8»;
  - ошибка API — flash `error` с текстом ошибки (неверный ключ, 429, тайм-аут);
  - нет ключа — «Ключ ROUTERAI_API_KEY не задан в .env»;
  - модели нет в RouterAI / нет картинок / нет ответа по схеме JSON — ошибка под полем (`is-invalid`);
  - список моделей недоступен — сохранение проходит, flash `warning` «Не удалось проверить модели по списку
    RouterAI».

## Контекст (проверено 16.11.2026)
- В БД модели не сохранены (есть только пустой `chrome_path`) — нужны модели по умолчанию.
- `httpApiRequest()` уже принимает JSON-тело и свои заголовки — ключ передаётся заголовком `Authorization`, как ключ
  PageSpeed (`X-Goog-Api-Key`).
- «Проверить Chrome» сделана второй кнопкой формы (`action=check`) — «Проверить подключение» так же, маршруты не
  меняются (`POST /settings`).
- `ai_results.diagnostic_id` — NOT NULL, поэтому проверка подключения в `ai_results` не пишется; настоящие записи
  появятся в таске 2, здесь — функции записи и подготовка данных для неё.
- `install.php` умеет только `CREATE TABLE IF NOT EXISTS`; FK `findings.ai_result_id` — через `ALTER TABLE` с
  проверкой `information_schema` (повторный запуск не падает).
- `GET https://routerai.ru/api/v1/models` отвечает без ключа: `architecture.input_modalities`,
  `supported_parameters`, `pricing` (рубли за токен). `qwen/qwen4-max`: текст / картинки / видео, `response_format`
  и `structured_outputs` есть, вход 0,00016019562 ₽, выход 0,00048058686 ₽ за токен.

## Решения
- **Модели по умолчанию:** константа `AI_DEFAULT_MODEL = 'qwen/qwen4-max'` для всех трёх задач;
  `resolveModelSetting()` — сохранённая модель или модель по умолчанию (через неё модель берут ИИ-шаги тасков 2–4).
- **Список моделей:** `GET {ROUTERAI_BASE_URL}/models` без ключа, кэш на сутки в `storage/cache/routerai/models.json`;
  RouterAI не ответил — старый кэш любой давности; кэша нет — модели не проверяются, сохранение с предупреждением.
- **Проверка моделей при сохранении:**
  - модель есть в списке;
  - в `supported_parameters` есть `response_format` (ответ по схеме JSON нужен всем трём задачам);
  - для `model_visual` в `input_modalities` есть `image`.
- **Запрос:** `POST {ROUTERAI_BASE_URL}/chat/completions` — `model`, `messages`, `max_tokens` (константа),
  `response_format: {type: json_schema, json_schema: {name, strict: true, schema}}`, `reasoning_effort: low`.
  Какое поле RouterAI понимает на самом деле (`reasoning_effort` или `reasoning.effort`) — проверить живым запросом.
- **Картинки:** `image_url` с `data:image/jpeg;base64,…`; в запись для `ai_results.request_json` картинка не
  попадает — вместо неё `{"screenshot_id": N}` или `"[image]"`.
- **Тайм-аут:** `AI_TIMEOUT_SECONDS = 180`, для проверки подключения — 60 с; не уложился — «RouterAI не ответил
  вовремя».
- **Повторы:** при 429 / 5xx / сетевой ошибке, до 3 попыток всего, паузы — константы (пауза передаётся параметром,
  тесты не ждут); при 400 / 401 / 402 / 403 повтора нет.
- **Разбор ответа:** `choices[0].message.content`, `model` (какая модель ответила на самом деле),
  `usage.prompt_tokens` / `usage.completion_tokens`, `finish_reason`; ошибка — из `error.message`; пустой ответ или
  обрыв по `length` — ошибка с понятным текстом.
- **Ключ:** только заголовком `Authorization: Bearer`; проверка допустимых символов; тексты ошибок проходят через
  `redact…()` (по образцу `PageSpeedService.php`). Ключа нет в `ai_results`, `app.log`, flash, сообщениях.
- **Тестовый запрос «Проверить подключение»:** модель просят ответить JSON `{"ok": true}` по схеме (проверяется и
  ответ по схеме). Одна модель в трёх полях — один запрос, разные — по запросу на каждую. `set_time_limit` с запасом.
- **Сеть в тестах:** транспорт — `callable` (как в `NvdClient` / `PageSpeedService`), тесты в сеть не ходят.

## Scope — что трогаем
- [x] `database/install.php` — изменить: таблица `ai_results` (по `.docs/database.md`); FK `fk_findings_ai_result`
      (`findings.ai_result_id → ai_results.id ON DELETE SET NULL`) через `ALTER TABLE`, если его нет в
      `information_schema`
- [x] `.docs/database.md` — изменить: пометка у `findings.ai_result_id`, что FK добавлен в фазе 4
- [x] `src/Services/AiClient.php` — создать: сборка запроса, кусок сообщения с картинкой, отправка с повторами,
      разбор ответа, описание ошибок, вырезание ключа, подготовка записи для `ai_results` без ключа и картинок
- [x] `src/Services/AiModels.php` — создать: загрузка и кэш списка моделей, поиск модели, «понимает картинки»,
      «отвечает по схеме JSON», цена за 1 млн токенов в рублях, строка о модели для подсказки
- [x] `src/Models/AiResultModel.php` — создать: `insertAiResult()`, `getAiResultsByDiagnostic()`
- [x] `src/Services/SettingsService.php` — изменить: `AI_DEFAULT_MODEL`, `resolveModelSetting()`, проверка моделей
      по списку RouterAI
- [x] `src/Controllers/SettingsController.php` — изменить: модели по умолчанию в форме, проверка по списку,
      `action=check` → сохранить и проверить подключение → redirect + flash
- [x] `src/Views/pages/settings.php` — изменить: строка о модели под полем, кнопка «Проверить подключение», текст
      плашки про модели по умолчанию
- [x] `tests/Unit/AiClientTest.php`, `tests/Unit/AiModelsTest.php` — создать; `tests/Unit/SettingsServiceTest.php` —
      изменить
- [x] `tests/fixtures/routerai/` — создать: урезанный настоящий `models.json` (`qwen/qwen4-max`; модель без картинок
      `qwen/qwen3.7-max`; модель без `response_format`), успешный ответ `chat/completions`, ошибки 401 и 429

## Out of scope — не трогаем
- ИИ-шаги `ai_problem` / `ai_visual` / `ai_summary`, их пропуск без ключа, `DiagnosticRunner.php` — таски 2–4
- `FactPacker`, промпты `config/prompts/`, проверка ссылок на факты — таск 2
- Обрезка скриншотов через GD — таск 3
- ИИ-блоки и стоимость в отчёте — таск 4
- Перезапуск ИИ-анализа — таск 5
- Выпадающий список / поиск моделей в настройках (поле остаётся текстовым)
- Запись проверки подключения в `ai_results` (нет диагностики, `diagnostic_id` NOT NULL)
- Рефакторинг `httpApiRequest()` и `PageSpeedService.php`

## Definition of Done
- [x] `php database/install.php`: таблица `ai_results` создана, FK `fk_findings_ai_result` есть; повторный запуск —
      без ошибок, данные не тронуты
- [x] В пустой БД на `/settings` в трёх полях `qwen/qwen4-max`, под каждым — строка о модели (картинки, цена)
- [x] «Проверить подключение» с настоящим ключом — успех с моделью, временем и токенами; с испорченным ключом
      (временная правка `.env`) — текст ошибки RouterAI; без ключа — «Ключ ROUTERAI_API_KEY не задан в .env»
- [x] Несуществующая модель не сохраняется (ошибка под полем); `qwen/qwen3.7-max` не сохраняется в «Проверка
      вёрстки», но сохраняется в «Разбор проблемы»
- [x] Список моделей недоступен (подменён адрес, кэша нет) — сохранение проходит с предупреждением, страница
      открывается
- [x] Повтор при 429 / 5xx, без повтора при 401; после 3 попыток — ошибка (тест)
- [x] Ключа нет в записи для `ai_results`, текстах ошибок, `app.log`, flash (тест + поиск по `app.log`); картинки в
      записи заменены ссылкой (тест)
- [x] Настройки проверены на 360 / 768 / 1280 px, кнопки не меньше 44 px, консоль чистая
- [x] Экран собран из компонентов Tabler по разделу «Образец», состояния реализованы
- [x] `composer test` проходит (PHP 8.3 OpenServer)
- [x] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
