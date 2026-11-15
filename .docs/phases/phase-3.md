# Phase 3 — Проверка в браузере

## Цель
В отчёте видны ошибки JavaScript и упавшие запросы с каждой проверенной страницы, скриншоты на ширинах 360 / 768 / 1366 и оценка скорости от PageSpeed Insights.

## Статус
⏳ Ожидает

> План предварительный: перепроверить через `.docs/prompts/phase-init.md` перед началом фазы.
> Нужно: Google Chrome на ПК, ключ PageSpeed Insights (открытый вопрос PRD).

## Таски

| # | Название | Статус |
|---|----------|--------|
| 1 | Подключить Chrome без окна | ⏳ Ожидает |
| 2 | Ошибки JS и упавшие запросы | ⏳ Ожидает |
| 3 | Скриншоты на трёх ширинах | ⏳ Ожидает |
| 4 | Скорость через PageSpeed Insights | ⏳ Ожидает |

---

## Таск 1 — Подключить Chrome без окна

**Статус:** ⏳ Ожидает

**Цель таска:**
Воркер запускает Chrome в headless-режиме через `chrome-php/chrome`, открывает страницу и гарантированно закрывает браузер; без Chrome шаги браузера пропускаются.

**Что нужно создать/изменить:**
- `composer.json` — `chrome-php/chrome` в `require`, автозагрузка `vendor/autoload.php` в воркере
- `src/Services/BrowserService.php` — запуск (путь из настройки `chrome_path`), открытие страницы с тайм-аутом, закрытие в `finally`
- `src/Views/settings/index.php`, `SettingsService.php` — поле `chrome_path` + кнопка «Проверить Chrome»
- `src/Services/DiagnosticRunner.php` — шаг `browser`, `skipped` если Chrome недоступен

**Definition of Done:**
- [ ] «Проверить Chrome» показывает версию или понятную ошибку
- [ ] После диагностики в диспетчере задач не остаётся процессов `chrome.exe` от воркера
- [ ] Неверный путь к Chrome → шаг `skipped` с причиной, отчёт строится
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 2 — Ошибки JS и упавшие запросы

**Статус:** ⏳ Ожидает

**Цель таска:**
На каждой проверенной странице собираются ошибки консоли, необработанные исключения и сетевые запросы с ошибкой или кодом 4xx/5xx.

**Что нужно создать/изменить:**
- `src/Services/Checks/BrowserConsoleCheck.php` — подписка на `Runtime.exceptionThrown`, `Log.entryAdded`, `Network.loadingFailed`, `Network.responseReceived`
- `src/Services/FindingFactory.php` — категории `js_error`, `failed_request`, группировка повторов
- `src/Views/components/finding.php` — вывод файла, строки и текста ошибки JS
- `tests/Unit/BrowserConsoleCheckTest.php` — разбор заготовленных событий

**Definition of Done:**
- [ ] Ошибка `Uncaught TypeError` на тестовой странице попадает в отчёт со страницей, файлом и строкой
- [ ] Запросы к счётчикам/рекламе, заблокированные браузером, не считаются поломкой (список исключений)
- [ ] Повторяющаяся ошибка — одна находка со счётчиком
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 3 — Скриншоты на трёх ширинах

**Статус:** ⏳ Ожидает

**Образец:** образца нет

**Цель таска:**
Для каждой проверенной страницы есть скриншоты 360 / 768 / 1366, они видны в отчёте и открываются крупно.

**Что нужно создать/изменить:**
- `src/Services/ScreenshotService.php` — смена ширины, ожидание загрузки, полноразмерный снимок с ограничением высоты, сохранение в `storage/screenshots/<id>/`
- `src/Models/ScreenshotModel.php` + таблица `screenshots`
- `src/Controllers/ScreenshotController.php` — отдача файла по id из БД; путь только внутри `storage/screenshots/`
- `src/Views/components/screenshot-gallery.php`
- `config/routes.php` — `/screenshots/{id}`

**Definition of Done:**
- [ ] 3 скриншота на страницу, у картинок есть `alt` (страница + ширина)
- [ ] Несуществующий id → 404; произвольный путь (`../`) файл вне `storage/screenshots/` не отдаёт
- [ ] Очень длинная страница обрезается по лимиту высоты, а не роняет шаг
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 4 — Скорость через PageSpeed Insights

**Статус:** ⏳ Ожидает

**Цель таска:**
Шаг `pagespeed`: оценка для мобильных и десктопа и основные метрики (LCP, CLS, TBT / INP, FCP) для главной.

**Что нужно создать/изменить:**
- `src/Services/PageSpeedService.php` — запрос к API `runPagespeed` с ключом `PAGESPEED_API_KEY` из `.env`, тайм-аут, разбор ответа
- `src/Services/Checks/SpeedCheck.php` — находки `speed` по порогам Core Web Vitals
- `src/Views/diagnostics/report.php` — блок скорости
- `tests/Unit/PageSpeedServiceTest.php` — разбор сохранённого JSON-ответа

**Definition of Done:**
- [ ] В отчёте видны оценки mobile / desktop и метрики
- [ ] Без ключа или при ошибке API шаг `skipped` с причиной, ключ не пишется в лог
- [ ] Проверить `.docs/dod-global.md`
