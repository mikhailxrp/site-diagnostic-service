# Phase 3 — Проверка в браузере

## Цель
В отчёте видны ошибки JavaScript и упавшие запросы с каждой проверенной страницы, скриншоты на ширинах
360 / 768 / 1366 и оценка скорости от PageSpeed Insights.

## Статус
✅ Завершена

> План перепроверен 16.11.2026 с учётом сделанного в Фазах 1–2 (`phase-init.md`).
> Дизайн: образцов нет — экраны из стандартных компонентов Tabler (решение владельца, 15.11.2026).
> Экраны: настройки — `src/Views/pages/settings.php`, отчёт — `src/Views/pages/diagnostic.php` (данные готовит
> `src/Services/ReportBuilder.php`), компоненты — `src/Views/components/`.
> Окружение (проверено 16.11.2026): Google Chrome 157 — `C:\Program Files\Google\Chrome\Application\chrome.exe`;
> PHP 8.3 OpenServer — расширение `sockets` включено; `chrome-php/chrome` v1.16.2 (PHP `^8.0.2`).
> Ключ PageSpeed Insights (`PAGESPEED_API_KEY`) лежит в `.env` — таск 4 не блокирован.

**Решения:**
- Страницы для браузера — те, что нашёл шаг `crawl` (главная, каталог, товар, корзина, оформление). Страницы с
  `checked_pages.blocked = 1` в браузере не открываются.
- Каждая страница загружается на каждой ширине (360 / 768 / 1366): ошибки JS собираются со всех загрузок
  (часть ошибок бывает только на мобильной вёрстке), повторы склеиваются в одну находку.
- Heartbeat: диагностика без отметки воркера 10 минут (`HEARTBEAT_STALE_SECONDS`) считается прерванной —
  шаг `browser` обновляет отметку после каждой загрузки.
- Упавший запрос, который уже есть среди находок `broken_resource` (шаг `resources`), второй находкой не
  становится.
- Тайм-ауты Chrome и PageSpeed — именованные константы (лимитов обхода нет, решение владельца).

**Сайты для ручной проверки:** https://sudar.su/, https://cleansan.ru/, https://www.citilink.ru/ (как в Фазе 2)
и любой магазин на WordPress / WooCommerce.

## Таски

| # | Название | Статус |
|---|----------|--------|
| 1 | Подключить Chrome без окна | ✅ Завершён |
| 2 | Ошибки JS и упавшие запросы | ✅ Завершён |
| 3 | Скриншоты на трёх ширинах | ✅ Завершён |
| 4 | Скорость через PageSpeed Insights | ✅ Завершён |

---

## Таск 1 — Подключить Chrome без окна

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler (поле на странице настроек)

**Цель таска:**
Воркер запускает Chrome без окна через `chrome-php/chrome`, открывает каждую проверенную страницу с тайм-аутом
и гарантированно закрывает браузер. Без Chrome шаг `browser` — `skipped` с причиной, отчёт строится.

**Что нужно создать/изменить:**
- `composer.json` — `chrome-php/chrome` `^1.16` в `require`; `vendor/autoload.php` подключается в
  `config/config.php`
- `src/Services/BrowserService.php` — запуск по настройке `chrome_path`, отдельная временная папка профиля в
  `storage/`, тайм-ауты константами, закрытие браузера и удаление профиля в `finally`
- `src/Services/SettingsService.php`, `src/Controllers/SettingsController.php`, `src/Views/pages/settings.php` —
  поле `chrome_path` (по умолчанию стандартный путь) и кнопка «Проверить Chrome» (POST + CSRF → redirect + flash)
- `src/Services/DiagnosticRunner.php` — шаг `browser` после `seo`: пропуск страниц с `blocked`, heartbeat после
  каждой загрузки
- `tests/Unit/BrowserServiceTest.php` — проверка пути, сборка параметров запуска
- Сделано сверх плана / по-другому: страница «открыта» по `DOMContentLoaded`, `load` ждём ещё не дольше 10 с
  (на cleansan.ru `load` — через 35 с); свой `Page.navigate` вместо `waitForNavigation()` — из ответа берётся
  `errorText` (`net::ERR_NAME_NOT_RESOLVED`), иначе страница ошибки Chrome считалась загруженной;
  `CHROME_DEFAULT_PATH` — в `SettingsService.php` (тесты не подключают `config.php`); `composer.json` —
  `config.platform.php = 8.3.6`; composer запускать PHP из OpenServer (у системного PHP 8.4 нет `sockets`)

**Definition of Done:**
- [x] «Проверить Chrome» показывает версию браузера или понятную ошибку
- [x] После диагностики (в том числе упавшей) в диспетчере задач нет процессов `chrome.exe` от воркера,
      временная папка профиля удалена
- [x] Неверный путь к Chrome → шаг `skipped` с причиной, отчёт строится
- [x] Диагностика с 5 страницами не помечается прерванной (heartbeat обновляется)
- [x] Проверить `.docs/dod-global.md`

---

## Таск 2 — Ошибки JS и упавшие запросы

**Статус:** ✅ Завершён

**Образец:** образца нет — существующий компонент находки

**Цель таска:**
На каждой странице и каждой ширине собираются необработанные исключения, ошибки консоли, запросы с сетевой
ошибкой и с ответом 4xx/5xx; они становятся находками `js_error` / `failed_request` со страницей, файлом и
строкой.

**Что нужно создать/изменить:**
- `src/Services/BrowserService.php` — подписка на `Runtime.exceptionThrown`, `Log.entryAdded`,
  `Network.loadingFailed`, `Network.responseReceived`
- `src/Services/Checks/BrowserConsoleCheck.php` — разбор событий без браузера (чистые функции), склейка повторов
  со счётчиком страниц и ширин, пропуск того, что уже есть среди `broken_resource`
- `data/browser-ignore.php` — счётчики и реклама (Метрика, GA, VK и т. п.) и отказы, которые вызвал сам браузер
  (`ERR_BLOCKED_BY_CLIENT`, отменённые запросы)
- `src/Services/FindingFactory.php` — серьёзность по умолчанию для `js_error`, `failed_request`
- `src/Views/components/finding.php` — файл, строка, текст ошибки, ширины
- `tests/Unit/BrowserConsoleCheckTest.php` + фикстуры событий `tests/fixtures/browser/`
- Сделано сверх плана / по-другому: загрузка на трёх ширинах перенесена сюда из таска 3 (таск 3 добавит к ним
  скриншоты); `FindingFactory.php` менять не понадобилось (категории уже были), зато изменён `ReportBuilder.php`
  (строка и ширины в находке); собирается и `console.error` (`Runtime.consoleAPICalled`); серьёзность — по домену
  файла / запроса (свой `medium`, чужой `low`, консоль `low`); правила ложных срабатываний (по итогам живой
  проверки): ошибки гидратации React #418 / #423 / #425, бейдж `pay.yandex.ru`, склейка запросов по адресу без
  параметров, повторный запрос статических ресурсов, сетевая ошибка — только если повторилась в 2 загрузках

**Definition of Done:**
- [x] `Uncaught TypeError` на тестовой странице попадает в отчёт со страницей, файлом и строкой
- [x] Запросы к счётчикам / рекламе и отказы, вызванные самим браузером, не считаются поломкой (тест)
- [x] Повторяющаяся ошибка — одна находка со счётчиком (тест)
- [x] 404 по ресурсу, который уже есть среди `broken_resource`, не даёт второй находки
- [x] Проверить `.docs/dod-global.md`

---

## Таск 3 — Скриншоты на трёх ширинах

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler (миниатюры в карточке «Проверенные страницы», крупно — модальное
окно Tabler)

**Цель таска:**
Для каждой проверенной страницы есть скриншоты 360 / 768 / 1366, они видны в отчёте и открываются крупно;
при удалении диагностики удаляются и их файлы.

**Что нужно создать/изменить:**
- `database/install.php` — таблица `screenshots` (по `.docs/database.md`)
- `src/Services/ScreenshotService.php` — смена ширины, ожидание загрузки, снимок всей страницы с пределом высоты
  (константа), сохранение в `storage/screenshots/<diagnostic_id>/`, удаление папки диагностики
- `src/Models/ScreenshotModel.php`
- `src/Controllers/ScreenshotController.php`, `config/routes.php` — `/screenshots/{id}`: файл по id из БД, путь
  проверяется через `realpath` внутри `storage/screenshots/`
- `src/Views/components/screenshot-gallery.php`, `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php`
- `src/Controllers/HistoryController.php` — удаление диагностики удаляет и файлы скриншотов
- Сделано сверх плана / по-другому: снимок делает `captureBrowserScreenshots()` в `BrowserService.php` (объекты
  chrome-php — только там), в `ScreenshotService.php` — расчёты, пути, запись и удаление; миниатюра (320 px, верх
  страницы) снимается самим Chrome (`clip.scale`), а не GD; маршрут `/screenshots/{id}/thumb`; изменены также
  `DiagnosticRunner.php` и `DiagnosticController.php`; без прокрутки страницы перед снимком (решение владельца)

**Definition of Done:**
- [x] 3 скриншота на страницу, у картинок `alt` вида «Главная, 360 px»
- [x] Несуществующий id → 404; файл вне `storage/screenshots/` не отдаётся
- [x] Очень длинная страница обрезается по пределу высоты, а не роняет шаг
- [x] Удаление диагностики в истории удаляет папку `storage/screenshots/<id>/`
- [x] Отчёт проверен на 360 / 768 / 1280 px
- [x] Проверить `.docs/dod-global.md`

---

## Таск 4 — Скорость через PageSpeed Insights

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler (блок «Скорость» в отчёте)

**Цель таска:**
Шаг `pagespeed` для главной: оценки mobile / desktop, лабораторные метрики (LCP, CLS, TBT, FCP) и, если есть
у Google, данные реальных пользователей (в том числе INP); находки `speed` по порогам Core Web Vitals.

**Что нужно создать/изменить:**
- `src/Services/PageSpeedService.php` — запрос `runPagespeed` (mobile + desktop) с ключом `PAGESPEED_API_KEY`
  из `.env`, тайм-аут константой, разбор ответа
- `src/Services/Checks/SpeedCheck.php` — пороги (LCP 2,5 / 4 с, CLS 0,1 / 0,25, INP 200 / 500 мс,
  TBT 200 / 600 мс, оценка < 50) → находки `speed`
- `src/Services/DiagnosticRunner.php` — шаг `pagespeed`, итог в `summary_json.pagespeed`
- `src/Services/ReportBuilder.php`, `src/Views/pages/diagnostic.php` — блок «Скорость»
- `tests/Unit/PageSpeedServiceTest.php`, `tests/Unit/SpeedCheckTest.php` + сохранённый ответ
  `tests/fixtures/pagespeed/`
- Сделано сверх плана / по-другому: ключ — заголовком `X-Goog-Api-Key`, а не в адресе (в `httpApiRequest()`
  добавлен необязательный параметр заголовков); тайм-аут 120 с (mobile на sudar.su — 67 с); ошибка загрузки
  страницы приходит как HTTP 400, а не `runtimeError`; отзывчивость — INP пользователей, без них TBT (одна
  находка); пороги FCP — только для таблицы; предупреждения Lighthouse (`runWarnings`) в карточке; изменён и
  `DiagnosticRunnerTest.php` (порядок шагов)

**Definition of Done:**
- [x] В отчёте видны оценки mobile / desktop и метрики; данные реальных пользователей подписаны отдельно
- [x] Без ключа или при ошибке API (429, тайм-аут) шаг `skipped` с причиной
- [x] Ключ не попадает в `app.log` и в сообщение шага (он в адресе запроса — проверить и ошибки curl)
- [x] Проверить `.docs/dod-global.md`
