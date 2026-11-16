# Current Task

## Фаза
Phase 3 — Проверка в браузере

## Задача
После каждой загрузки страницы (360 / 768 / 1366) делается снимок всей страницы с пределом высоты и миниатюра её
верха. Снимки хранятся в `storage/screenshots/<diagnostic_id>/`, видны в отчёте миниатюрами в карточке «Проверенные
страницы», открываются крупно в модальном окне Tabler и удаляются вместе с диагностикой.

## Образец
Образца нет: экран из компонентов Tabler (решение владельца, 15.11.2026).
- **Миниатюры:** в строке страницы карточки «Проверенные страницы» — три миниатюры с подписью «360 px / 768 px /
  1366 px» (`img-thumbnail`, файл миниатюры, `loading="lazy"`). Каждая — кнопка, открывающая крупный вид.
- **Крупный вид:** `modal modal-xl` (Bootstrap из `tabler.min.js`, уже подключён), в нём полный снимок `w-100`
  с `loading="lazy"`, окно прокручивается. Своего CSS и JS нет.
- **Состояния:** снимки есть; снимков нет — «Скриншотов нет» (с причиной из фактов шага, если есть); страница за
  защитой от ботов — блок не выводится.

## Контекст (проверено 16.11.2026)
- `openBrowserPage()` уже открывает страницу на нужной ширине (таск 2) и закрывает вкладку в `finally` — снимок
  делается до закрытия. Пункт плана фазы «смена ширины, ожидание загрузки» уже сделан; `ScreenshotService` — только
  снимок, сохранение, удаление.
- Одна загрузка — один `page_id`, но адрес может покрывать несколько типов (главная = каталог,
  `collectResourcePages()`). В отчёте снимки сопоставляются со строкой страницы по адресу
  (`normalizeFindingLocation()`), а не только по `page_id`.
- `deleteDiagnostic()` удаляет строки `screenshots` каскадом (FK), файлы — нет.
- `database/install.php` идемпотентен: на существующей БД таблицу создаёт повторный запуск
  `php database/install.php`.
- Полный снимок 1366 × 10000 в JPEG — ~1,5–3 МБ; 15 таких картинок как миниатюры — десятки МБ на отчёт. GD в PHP 8.3
  OpenServer есть, но раскодирование такого снимка — ~55 МБ памяти; поэтому миниатюру снимает сам Chrome.

## Решения
- **Когда снимать:** только при статусе загрузки `opened`, после `BROWSER_SETTLE_MS`. `timeout` / `error` — без
  снимков.
- **Полный снимок:** `Page.getLayoutMetrics` → высота содержимого; `Page.captureScreenshot` с `captureBeyondViewport`
  и `clip`: ширина = ширина окна, высота = `min(высота, SCREENSHOT_MAX_HEIGHT_PX = 10000)`. Обрезка — факт загрузки
  `screenshot_truncated`, не ошибка.
- **Миниатюра:** второй `Page.captureScreenshot` верха страницы: `clip` ширина окна × ширина·4/3 (не выше
  содержимого), `scale = SCREENSHOT_THUMB_WIDTH_PX (320) / ширина`. GD не используется.
- **Формат:** JPEG, `SCREENSHOT_JPEG_QUALITY = 80`, миниатюра — `SCREENSHOT_THUMB_JPEG_QUALITY = 70`.
- **Ленивые картинки:** перед снимком страница не прокручивается — `loading="lazy"` ниже первого экрана может быть
  пустым (решение владельца, 16.11.2026).
- **Файлы:** `storage/screenshots/<diagnostic_id>/<page_id>-<width>.jpg` и `<page_id>-<width>-thumb.jpg`.
  `file_path` — путь полного снимка относительно `storage/screenshots/`; путь миниатюры выводится из него
  (`-thumb` перед расширением), схема БД не меняется. Без `page_id` снимок не сохраняется (`page_id NOT NULL`).
  Запись в БД — только когда оба файла сохранены.
- **Ошибка снимка** не роняет шаг: факт загрузки `screenshot_error`, в сообщении шага «скриншотов: N из M».
- **Отдача:** `GET /screenshots/{id}` (полный) и `GET /screenshots/{id}/thumb` (миниатюра) → запись из БД, путь от
  `storage/screenshots/`, `realpath` обязан лежать внутри неё; `Content-Type: image/jpeg`, `Content-Length`,
  `Cache-Control: private`. Нет записи / файла / путь вне папки — 404 без подробностей, причина — в лог.
- **Удаление:** после успешного `deleteDiagnostic()` — `deleteDiagnosticScreenshots($id)`; не удалось удалить файлы —
  `logWarning`, флеш остаётся успешным (запись уже удалена).
- **alt:** «Главная, 360 px» (тип — `REPORT_PAGE_TYPE_LABELS`); у миниатюры — «Главная, 360 px, миниатюра».

## Scope — что трогаем
- [ ] `database/install.php` — изменить (таблица `screenshots` по `.docs/database.md`)
- [ ] `src/Services/ScreenshotService.php` — создать (расчёт клипов полного снимка и миниатюры, имена файлов,
      сохранение, проверка пути внутри `storage/screenshots/`, удаление папки диагностики)
- [ ] `src/Services/BrowserService.php` — изменить (`openBrowserPage()` до закрытия вкладки вызывает необязательный
      обработчик снимка; `buildBrowserStepMessage()` — счётчик скриншотов)
- [ ] `src/Models/ScreenshotModel.php` — создать (`insertScreenshot`, `getScreenshot`, `getScreenshotsByDiagnostic`)
- [ ] `src/Services/DiagnosticRunner.php` — изменить (`runBrowserStep()`: снимки → файлы → запись в БД, факты
      `screenshot` / `screenshot_error` / `screenshot_truncated`)
- [ ] `src/Controllers/ScreenshotController.php` — создать (`show($id)`, `thumb($id)`)
- [ ] `config/routes.php` — изменить (`GET /screenshots/{id}`, `GET /screenshots/{id}/thumb`)
- [ ] `src/Controllers/HistoryController.php` — изменить (удаление файлов после удаления диагностики)
- [ ] `src/Controllers/DiagnosticController.php` — изменить (`loadReport()` передаёт скриншоты в `buildReport()`)
- [ ] `src/Services/ReportBuilder.php` — изменить (скриншоты в строках `buildReportPages()`: адреса, ширина, alt)
- [ ] `src/Views/components/screenshot-gallery.php` — создать (миниатюры + модальные окна)
- [ ] `src/Views/pages/diagnostic.php` — изменить (галерея в строке страницы)
- [ ] `tests/Unit/ScreenshotServiceTest.php` — создать (клипы и обрезка по пределу, масштаб миниатюры, имена файлов,
      путь вне папки и `..` отклоняются, удаление папки)
- [ ] `tests/Unit/BrowserServiceTest.php` — изменить (сообщение шага со скриншотами)
- [ ] `tests/Unit/ReportBuilderTest.php` — изменить (снимки в строке страницы, alt, сопоставление по адресу для двух
      типов)

## Out of scope — не трогаем
- PageSpeed Insights (таск 4)
- Анализ вёрстки по скриншотам, находки `layout` (Фаза 4)
- Прокрутка страницы для ленивых картинок, эмуляция устройств, подмена User-Agent
- Обработка картинок через GD / Imagick
- Сбор ошибок JS и запросов (`BrowserConsoleCheck`), страница настроек, `HttpClient`
- Изменение схемы в `.docs/database.md`
- Попутный рефакторинг

## Definition of Done
- [ ] После `php database/install.php` таблица `screenshots` есть, повторный запуск ничего не ломает
- [ ] Живая диагностика (cleansan.ru, sudar.su): 3 снимка + 3 миниатюры на каждую открытую страницу в
      `storage/screenshots/<id>/`, сообщение шага «…; скриншотов: N из N»
- [ ] Миниатюра весит в разы меньше полного снимка (проверить размеры файлов), отчёт не грузит полные снимки, пока
      не открыт крупный вид (вкладка Network)
- [ ] У картинок `alt` вида «Главная, 360 px»; крупный вид открывается модальным окном Tabler, закрывается Esc /
      крестиком
- [ ] Страница, которая и главная, и каталог, показывает снимки в обеих строках (тест)
- [ ] Несуществующий и нечисловой id → 404; запись с `file_path` за пределы `storage/screenshots/` (`../`) → 404,
      файл не отдаётся (тест + ручная правка записи в БД)
- [ ] Очень длинная страница обрезается по `SCREENSHOT_MAX_HEIGHT_PX`, шаг `done` (тест расчёта клипа + вживую)
- [ ] Ошибка снимка не роняет шаг, причина видна в фактах
- [ ] citilink.ru: страницы за защитой от ботов без снимков, воркер не падает
- [ ] Удаление диагностики в истории удаляет папку `storage/screenshots/<id>/`
- [ ] Отчёт на 360 / 768 / 1280 px: миниатюры не ломают вёрстку, модальное окно помещается, консоль чистая
- [ ] Экран соответствует описанию из «Образец» по расположению и составу, состояния реализованы
- [ ] После диагностики нет `chrome.exe` и папок `storage/temp/chrome-*`
- [ ] `composer test` проходит (PHP 8.3 OpenServer), в `storage/logs/app.log` нет новых ошибок
- [ ] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
