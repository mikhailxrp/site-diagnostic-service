# Схема БД

> Этот файл — детальное описание всех таблиц.
> Агент читает его при планировании тасков связанных с БД.
> Синхронизируй с database/install.php.
> Колонка «Фаза» — в какой фазе таблица появляется в `install.php`.

---

## Сводка

| Таблица | Назначение | Фаза |
|---|---|---|
| `settings` | настройки ключ → значение (ключи API, модели); лимитов обхода нет | 0 |
| `diagnostics` | одна диагностика сайта: ссылка, жалоба, статус, связь с прошлой | 0 |
| `diagnostic_steps` | шаги фонового выполнения и их прогресс | 0 |
| `checked_pages` | проверенные страницы сайта | 1 |
| `stack_items` | найденный стек: CMS, сервер, библиотеки, тема, плагины | 1 |
| `findings` | находки (поломки и проблемы) от проверок и от ИИ | 1 |
| `screenshots` | скриншоты страниц на разных ширинах | 3 |
| `ai_results` | сырые ответы ИИ по задачам | 4 |

---

## Таблицы

### `settings`

Хранилище «ключ → значение» для несекретных настроек. Ключи API и `ROUTERAI_BASE_URL` хранятся в `.env`, в БД их нет (решение 15.11.2026): `ROUTERAI_API_KEY`, `PAGESPEED_API_KEY`, `WPSCAN_API_KEY`.

| Колонка | Тип | Назначение |
|---------|-----|------------|
| setting_key | VARCHAR(64) PK | имя настройки |
| setting_value | TEXT NULL | значение |
| updated_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

Ключи настроек:

| Ключ | Пример / по умолчанию | Фаза |
|---|---|---|
| `model_problem` | модель для разбора проблемы | 0 / 4 |
| `model_visual` | модель для проверки вёрстки (должна понимать картинки) | 0 / 4 |
| `model_summary` | модель для итогового вывода | 0 / 4 |
| `chrome_path` | путь к `chrome.exe` | 3 |

### `diagnostics`

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| url | VARCHAR(2048) NOT NULL | ссылка, как ввёл владелец (после нормализации) |
| host | VARCHAR(255) NOT NULL | домен без `www.`, для поиска в истории и связи с прошлыми |
| product_url | VARCHAR(2048) NULL | адрес товара, если указан вручную (фаза 2) |
| problem_text | TEXT NULL | описание проблемы со слов клиента |
| status | ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued' | |
| progress | TINYINT UNSIGNED NOT NULL DEFAULT 0 | общий процент 0–100 |
| error_message | VARCHAR(1000) NULL | причина падения для `failed` |
| previous_id | INT NULL FK → diagnostics.id ON DELETE SET NULL | прошлая диагностика этого же `host` |
| summary_json | JSON NULL | сводные факты: доступность, SSL, время ответа, PageSpeed |
| started_at | DATETIME NULL | |
| finished_at | DATETIME NULL | |
| heartbeat_at | DATETIME NULL | последняя отметка воркера: по ней видно «зависшие» задания |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

Индексы: `INDEX(host)`, `INDEX(status)`, `INDEX(created_at)`.

### `diagnostic_steps`

Шаги выполнения — то, что видно на экране прогресса.

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| step_code | VARCHAR(50) NOT NULL | `availability`, `stack`, `crawl`, `resources`, `seo`, `browser`, `pagespeed`, `ai_problem`, … |
| title | VARCHAR(150) NOT NULL | подпись для экрана |
| position | SMALLINT UNSIGNED NOT NULL | порядок |
| status | ENUM('pending','running','done','skipped','failed') NOT NULL DEFAULT 'pending' | |
| message | VARCHAR(1000) NULL | что пошло не так или почему пропущен |
| started_at | DATETIME NULL | |
| finished_at | DATETIME NULL | |

Индексы: `UNIQUE(diagnostic_id, step_code)`.

### `checked_pages`

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| url | VARCHAR(2048) NOT NULL | итоговый адрес |
| page_type | ENUM('home','category','product','cart','checkout','other') NOT NULL | |
| found_by | ENUM('start','cms_pattern','link_heuristic','manual') NOT NULL | как страница найдена |
| http_code | SMALLINT UNSIGNED NULL | NULL — не удалось запросить |
| response_ms | INT UNSIGNED NULL | время ответа |
| blocked | TINYINT(1) NOT NULL DEFAULT 0 | защита от ботов / капча, «не удалось проверить» |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

### `stack_items`

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| category | ENUM('cms','server','php','cdn','js_library','theme','plugin','analytics','other') NOT NULL | |
| name | VARCHAR(150) NOT NULL | например `WordPress`, `jQuery`, `WooCommerce` |
| version | VARCHAR(50) NULL | если удалось определить |
| latest_version | VARCHAR(50) NULL | актуальная версия из справочника (фаза 2) |
| confidence | ENUM('high','medium','low') NOT NULL | степень уверенности |
| evidence | JSON NOT NULL | откуда взят признак: `[{"source":"header","detail":"X-Powered-By: PHP/7.4"}]` |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

### `findings`

Одна таблица для находок от кода и от ИИ. ИИ-находка обязана ссылаться на факты.

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| page_id | INT NULL FK → checked_pages.id ON DELETE SET NULL | на какой странице найдено |
| source | ENUM('check','ai') NOT NULL | кто нашёл: код или ИИ |
| category | VARCHAR(50) NOT NULL | `availability`, `ssl`, `broken_resource`, `php_error`, `mixed_content`, `js_error`, `failed_request`, `outdated`, `vulnerability`, `exposed_file`, `seo`, `speed`, `checkout`, `layout`, `hypothesis` |
| severity | ENUM('critical','high','medium','low','info') NOT NULL | |
| title | VARCHAR(255) NOT NULL | коротко, для списка |
| details | TEXT NULL | подробности (тех. язык) |
| client_text | TEXT NULL | то же простым языком для клиентской версии (фаза 5) |
| location | VARCHAR(2048) NULL | адрес ресурса / страницы / файла |
| evidence | JSON NULL | для `check` — сырые данные (код ответа, фрагмент вывода); для `ai` — ссылки на факты: `{"finding_ids":[..],"stack_ids":[..],"screenshot_ids":[..]}` |
| fingerprint | CHAR(40) NOT NULL | sha1 от `category + нормализованный location + ключ находки`; по нему сравниваются диагностики (фаза 5) |
| ai_result_id | INT NULL FK → ai_results.id ON DELETE SET NULL | какой ответ ИИ породил находку. В фазах 1–3 колонка была без FK; FK `fk_findings_ai_result` добавлен в фазе 4 через `ALTER TABLE` (`install.php`, `$foreignKeys`, только если его ещё нет) |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

Индексы: `INDEX(diagnostic_id, severity)`, `INDEX(fingerprint)`.

### `screenshots`

Файлы лежат в `storage/screenshots/<diagnostic_id>/` (вне `public/`), отдаются через контроллер (входа нет; контроллер проверяет, что запись есть в БД и файл лежит внутри `storage/screenshots/`).

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| page_id | INT NOT NULL FK → checked_pages.id ON DELETE CASCADE | |
| viewport_width | SMALLINT UNSIGNED NOT NULL | 360 / 768 / 1366 |
| file_path | VARCHAR(255) NOT NULL | путь относительно `storage/screenshots/` |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

### `ai_results`

| Колонка | Тип | Назначение |
|---------|-----|------------|
| id | INT PK AUTO_INCREMENT | |
| diagnostic_id | INT NOT NULL FK → diagnostics.id ON DELETE CASCADE | |
| task | ENUM('problem','visual','summary','client_text') NOT NULL | ИИ-задача |
| model | VARCHAR(150) NOT NULL | какая модель ответила |
| status | ENUM('ok','error') NOT NULL | |
| request_json | JSON NULL | что отправили (без ключа API, картинки — только id скриншотов) |
| response_text | MEDIUMTEXT NULL | ответ модели как есть |
| parsed_json | JSON NULL | разобранный структурированный ответ |
| error_message | VARCHAR(1000) NULL | |
| tokens_in | INT UNSIGNED NULL | |
| tokens_out | INT UNSIGNED NULL | |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

---

## Карта связей

```
settings (ключ → значение, без связей)

diagnostics (1)
  ├──< diagnostics          (previous_id → прошлая диагностика того же host)
  ├──< diagnostic_steps     (1:N)
  ├──< checked_pages        (1:N)
  │      ├──< findings      (page_id, необязательно)
  │      └──< screenshots   (1:N)
  ├──< stack_items          (1:N)
  ├──< findings             (1:N)
  └──< ai_results           (1:N)
         └──< findings      (ai_result_id, только source = 'ai')
```

---

## Правила

- Все FK на `diagnostics.id` → `ON DELETE CASCADE` (удаление диагностики чистит всё её содержимое; файлы скриншотов удаляет код).
- Все таблицы → `ENGINE=InnoDB`, `utf8mb4_unicode_ci`.
- Таблицы `users` нет: входа нет, пользователь один (решение 15.11.2026).
- Ключи API не пишутся в логи и в `ai_results.request_json`.
- Изоляции по `user_id` нет: пользователь один (пункт DoD про чужие записи не применяется).
- Таблицы добавляются в `install.php` в той фазе, где они впервые нужны (см. «Сводка»); `install.php` идемпотентен (`CREATE TABLE IF NOT EXISTS`).
