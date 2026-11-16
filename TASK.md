# Current Task

## Фаза
Phase 3 — Проверка в браузере

## Задача
Шаг `pagespeed` после `browser`: главная проверяется через Google PageSpeed Insights (mobile + desktop), итог —
в `summary_json.pagespeed`, находки `speed` по порогам Core Web Vitals, в отчёте — карточка «Скорость».

## Образец
Образца нет: экран из компонентов Tabler (решение владельца, 15.11.2026). Своего CSS и JS нет.
- **Где:** карточка «Скорость» в `diagnostic.php` сразу после «Сводки» (блок один — отдельный компонент не нужен).
- **Оценка:** две плашки «Mobile» / «Desktop» с числом 0–100; цвет как у Google: ≥ 90 — `bg-success-lt`,
  50–89 — `bg-warning-lt`, < 50 — `bg-danger-lt`.
- **Лаборатория (Lighthouse):** `table table-vcenter` в `table-responsive`, колонки «Метрика / Mobile / Desktop»,
  строки LCP, CLS, TBT, FCP; значение с плашкой «хорошо» / «требует улучшения» / «плохо».
- **Реальные пользователи:** отдельная таблица той же формы с подписью «Реальные пользователи (Chrome UX Report,
  28 дней)», строки LCP, INP, CLS, FCP; если данные взяты по всему сайту, а не по странице — отметка «по всему сайту».
- **Предупреждения Lighthouse** (`runWarnings`, например «страница загружалась слишком медленно») — `alert
  alert-warning` под оценками.
- **Состояния:**
  - обе стратегии есть — карточка целиком;
  - одна стратегия упала — в её колонке «—», `alert alert-warning` с причиной;
  - нет данных реальных пользователей — «У Google нет данных реальных пользователей по этому сайту»;
  - шаг `skipped` — карточки нет, причина в «Не удалось проверить» (блок уже есть);
  - старая диагностика без `summary_json.pagespeed` — карточки нет.

## Контекст (проверено 16.11.2026 живыми запросами к API на sudar.su, example.com, несуществующий домен)
- Ключ `PAGESPEED_API_KEY` в `.env` есть и работает; на странице настроек показывается маской — правок не нужно.
- Категория `speed` уже есть в `FindingFactory.php` (`low`) и `ReportBuilder.php` («Скорость»).
- **Время:** mobile — 67 с, desktop — 50 с (sudar.su), example.com — 9 с. Ответ ~1 МБ (предел
  `HTTP_API_MAX_BODY_BYTES` 20 МБ хватает).
- **Ключ в заголовке:** API принимает `X-Goog-Api-Key` (проверено: 200). В адресе ключа не будет вообще —
  не попадёт ни в лог, ни в текст ошибки curl. `httpApiRequest()` сейчас не принимает своих заголовков.
- **Ответ 200:** `lighthouseResult.categories.performance.score` (0–1, может быть `null`), `audits.<id>.numericValue`
  (мс; CLS — без единиц), `lighthouseVersion` (13.6.0), `runWarnings` (уже на `locale=ru`), `analysisUTCTimestamp`;
  `loadingExperience` (по адресу) и `originLoadingExperience` (по сайту): `metrics.<KEY>.percentile` + `category`
  `FAST` / `AVERAGE` / `SLOW`. `CUMULATIVE_LAYOUT_SHIFT_SCORE` приходит ×100 (12 → 0,12). Данные пользователей
  у mobile и desktop разные (свой form factor).
- **Ошибки:** неверный ключ — 400, `error.message` «API key not valid…» (ключа в теле нет); страница не открылась —
  тоже 400: «Lighthouse returned error: FAILED_DOCUMENT_REQUEST. Не удалось загрузить страницу… net::ERR_CONNECTION_FAILED»,
  а не `runtimeError` в 200. Квота — 429.
- Адрес главной — `facts.home.final_url` шага `availability`. Клиенты NVD / OSV получают транспорт через `callable` —
  так же тестируется и PageSpeed без сети.

## Решения
- **Запрос:** `GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=…&strategy=mobile|desktop&category=performance&locale=ru`,
  ключ — заголовком `X-Goog-Api-Key`. Сначала mobile, потом desktop (последовательно); heartbeat — после каждого.
  `PAGESPEED_TIMEOUT_SECONDS = 120` (наблюдали 67 с — запас почти вдвое). Шаг занимает до ~2–4 минут, что в рамках
  `HEARTBEAT_STALE_SECONDS` (10 мин). Повторов при ошибке нет.
- **Когда шаг пропускается (`skipped`):** нет ключа — «Не задан PAGESPEED_API_KEY в .env»; главная не ответила
  (`status` null) — «Главная не ответила»; обе стратегии с ошибкой — причины обеих. Защита от ботов шаг не
  пропускает: страницу открывают серверы Google, а не этот ПК.
- **Причины ошибок:** 429 — «Превышен лимит запросов PageSpeed»; 4xx/5xx — `error.message` из тела (обрезан
  до разумной длины); тайм-аут / curl — текст `httpApiRequest()`; 200 с `lighthouseResult.runtimeError` —
  его `code` + `message`; 200 без оценки и метрик — «Ответ PageSpeed без результатов». Одна стратегия упала —
  шаг `done`, сообщение «mobile: 39; desktop: ошибка …».
- **Ключ не утекает:** он только в заголовке; дополнительно все тексты ошибок проходят через `redactPageSpeedKey()`
  (подстрока ключа → `[REDACTED]`) — на случай, если Google когда-нибудь вернёт его в теле.
- **Разбор:**
  - оценка — `round(score × 100)`, `null` → нет оценки;
  - лаборатория — `largest-contentful-paint`, `cumulative-layout-shift`, `total-blocking-time`,
    `first-contentful-paint` (`numericValue`);
  - пользователи — `LARGEST_CONTENTFUL_PAINT_MS`, `INTERACTION_TO_NEXT_PAINT`, `CUMULATIVE_LAYOUT_SHIFT_SCORE` (÷ 100),
    `FIRST_CONTENTFUL_PAINT_MS`; источник: `loadingExperience`, если есть `metrics` и нет `origin_fallback`
    → `field_scope = url`; иначе `originLoadingExperience.metrics` → `origin`; иначе данных нет;
  - сырой ответ не хранится.
- **`summary_json.pagespeed`:** `{url, mobile: {score, lab, field, field_scope, warnings, lighthouse_version,
  analyzed_at} | null, desktop: …, errors: {mobile?: reason, desktop?: reason}}`.
- **Пороги** (`SpeedCheck.php`, как у Google, граница «хорошо» включительно): LCP ≤ 2500 / ≤ 4000 мс,
  CLS ≤ 0,1 / ≤ 0,25, INP ≤ 200 / ≤ 500 мс, TBT ≤ 200 / ≤ 600 мс, FCP ≤ 1800 / ≤ 3000 мс. Один набор порогов
  для лаборатории и пользователей; тест сверяет оценку по порогам с `category` из сохранённого ответа.
- **Находки `speed`** (чистые функции), по стратегиям вместе — одна находка на показатель:
  - LCP и CLS — по данным пользователей, если есть, иначе по лаборатории;
  - отзывчивость — INP пользователей, если есть, иначе TBT лаборатории (TBT — лабораторная замена INP,
    двух находок про одно не будет);
  - оценка < 50 — отдельная находка;
  - FCP — только в таблице, без находки;
  - серьёзность по худшей стратегии: «плохо» и оценка < 50 → `medium`, «требует улучшения» → `low`;
  - `evidence`: значения по стратегиям и источник («реальные пользователи» / «лаборатория»); `page_id` главной;
    ключ `fingerprint` — `speed:<показатель>`.
- **Порядок шагов:** `… seo, browser, pagespeed`, название — «Скорость (PageSpeed)».

## Scope — что трогаем
- [ ] `src/Services/HttpClient.php` — изменить: `httpApiRequest()` — необязательный последний параметр
      `array $extraHeaders = []` (существующие вызовы не меняются)
- [ ] `src/Services/PageSpeedService.php` — создать (адрес запроса, заголовок с ключом, запрос через переданный
      транспорт, разбор ответа, причины ошибок, `redactPageSpeedKey()`, константы адреса и тайм-аута)
- [ ] `src/Services/Checks/SpeedCheck.php` — создать (пороги, оценка показателя, выбор источника, находки `speed`,
      сообщение шага)
- [ ] `src/Services/DiagnosticRunner.php` — изменить (шаг `pagespeed` в `getDiagnosticSteps()`, `runPageSpeedStep()`:
      ключ из `env()`, heartbeat после каждой стратегии, `updateDiagnosticSummary(['pagespeed' => …])`)
- [ ] `src/Services/ReportBuilder.php` — изменить (`buildReportPageSpeed()`: оценки с плашками, строки метрик,
      данные пользователей, предупреждения, ошибки стратегий; ключ `pagespeed` в `buildReport()`)
- [ ] `src/Views/pages/diagnostic.php` — изменить (карточка «Скорость»)
- [ ] `tests/fixtures/pagespeed/` — создать: настоящие ответы sudar.su mobile / desktop, урезанные скриптом до
      нужных полей; ошибки 400 (неверный ключ, `FAILED_DOCUMENT_REQUEST`); собранные вручную варианты (только
      данные сайта, без данных пользователей, `runtimeError`, `score: null`, 429) — помечены в имени файла
- [ ] `tests/Unit/PageSpeedServiceTest.php` — создать (адрес и кодирование `url`, ключ только в заголовке, разбор
      фикстур, `field_scope` url / origin / нет, CLS ÷ 100, `runtimeError`, `score: null`, 400 / 429 / тайм-аут →
      причина, `redactPageSpeedKey()`)
- [ ] `tests/Unit/SpeedCheckTest.php` — создать (границы порогов включительно, совпадение с `category` Google,
      приоритет пользователей, INP вместо TBT, оценка < 50, склейка стратегий, худшая серьёзность, всё хорошо →
      нет находок, одна стратегия упала, обе упали → `skipped`)
- [ ] `tests/Unit/ReportBuilderTest.php` — изменить (карточка «Скорость» и её состояния, нет `pagespeed` → null)
- [ ] `tests/Unit/DiagnosticRunnerTest.php` — изменить (порядок шагов с `pagespeed`)

## Out of scope — не трогаем
- PageSpeed для страниц, кроме главной; параллельный запуск mobile / desktop (`curl_multi`); повторы запросов
- Сырой ответ Lighthouse, раздел «Возможности оптимизации», скриншоты Lighthouse
- Страница настроек, `SettingsService.php`, `.env.example`
- Схема БД и `.docs/database.md` (используется `summary_json`)
- `BrowserService.php`, скриншоты, ошибки JS
- ИИ-анализ (Фаза 4), клиентский отчёт и PDF (Фаза 5)
- Попутный рефакторинг

## Definition of Done
- [ ] Живая диагностика sudar.su и cleansan.ru: в отчёте оценки mobile / desktop, лабораторные метрики и отдельно
      подписанные данные реальных пользователей (или сообщение, что их нет); предупреждения Lighthouse видны
- [ ] Цвета плашек оценки и метрик соответствуют порогам (тест + визуально)
- [ ] Плохие показатели дают находки `speed` со стратегиями и источником; один показатель — одна находка (тест)
- [ ] Пустой `PAGESPEED_API_KEY` → шаг `skipped` с причиной, отчёт строится, причина в «Не удалось проверить»
- [ ] Неверный ключ (400) и 429 → `skipped` с понятной причиной (тест + вживую с испорченным ключом)
- [ ] Ключа нет в `storage/logs/app.log`, `diagnostic_steps.message`, `summary_json` (поиск строки ключа после
      прогона с ошибкой)
- [ ] Упала одна стратегия → шаг `done`, в карточке «—» и предупреждение (тест)
- [ ] citilink.ru: шаг `done` или `skipped` с причиной, воркер не падает, диагностика не помечается прерванной
- [ ] Отчёт на 360 / 768 / 1280 px: таблицы не ломают вёрстку, консоль чистая
- [ ] Экран соответствует описанию из «Образец» по расположению и составу, состояния реализованы
- [ ] `composer test` проходит (PHP 8.3 OpenServer), в `storage/logs/app.log` нет новых ошибок
- [ ] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
