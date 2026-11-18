# Current Task

## Фаза
Phase 5 — Для клиента и углублённые проверки (таск 2 — Сценарий корзины: проход в браузере) — ✅ Завершён

## Задача
Шаг `checkout` (после `browser`) в Chrome проходит «товар → в корзину → корзина → форма оформления» на ширине 1366,
на каждом шаге делает скриншот и собирает ошибки консоли; заказ не отправляется ни при каком исходе.

## Образец
Без UI. Карточка «Сценарий заказа» — таск 3. Находки `checkout` пока выводит существующий
`src/Views/components/finding.php` (категория уже есть в `FindingFactory.php` и `ReportBuilder.php`).

## Как это работает
1. **Пропуск (`skipped`)** с причиной:
   - нет библиотеки или Chrome — те же тексты, что у `browser` (`BROWSER_NO_LIBRARY_MESSAGE`,
     `BROWSER_UNAVAILABLE_PREFIX`);
   - товар не найден: нет `facts.crawl.pages.product` и нет `product_url`;
   - страница товара за защитой от ботов (`bot_protection` из фактов `crawl` или проверка открытой страницы);
   - товара нет в наличии, у товара обязательные варианты (`variations_form`, `select[required]` и т. п.).
2. **Адреса.**
   - Товар: `facts.crawl.pages.product` (туда уже попадает `product_url` с `found_by = manual`). Если шаг `crawl`
     не дал фактов — `product_url`.
   - Корзина и оформление, по порядку: адрес из фактов `crawl` → ссылка на текущей странице (`cart_link*` /
     `checkout_link*` из `data/shop-patterns.php`) → типовые пути платформы → `generic`.
   - Платформа — `facts.crawl.platforms` (ключи `data/signatures.php`).
3. **Браузер.** Отдельный запуск Chrome (`launchBrowser()`), одна вкладка на весь сценарий (сессия и корзина
   сохраняются), закрытие и удаление профиля в `finally` (`closeBrowser()`). Используются готовые
   `navigateBrowserPage()`, `captureBrowserScreenshots()`, `createBrowserEventState()` / `applyBrowserEvent()`;
   `BrowserService.php` не меняется. Диалоги `alert` / `confirm` (`Page.javascriptDialogOpening`) закрываются
   отменой, иначе вкладка зависнет до тайм-аута.
4. **Шаги сценария** (у каждого: url, статус, скриншот 1366, ошибки консоли, причина):
   - `product` — открыть товар, проверить «нет в наличии» и обязательные варианты (→ `skipped`);
   - `add_to_cart` — найти кнопку: сначала селекторы платформы, потом общие тексты («В корзину», «Купить»).
     **Никогда не нажимаются** кнопки с текстами «в 1 клик», «быстрый заказ», «оформить», «заказать», «подтвердить»
     (стоп-лист в `data/checkout-selectors.php`). Нажатие — `element.click()` через `Runtime.evaluate`.
     Сигнал успеха (ждём не дольше `CHECKOUT_ADD_WAIT_MS`): изменился счётчик / мини-корзина, ушёл запрос
     добавления с ответом 2xx, или страница перешла в корзину;
   - `cart` — открыть корзину, проверить, что она не пуста (нет признака «корзина пуста», есть строка товара);
   - `checkout` — открыть оформление **переходом по адресу**, не нажатием кнопки; проверить, что есть форма с
     полями (имя / телефон / email). Поля не заполняются, ничего не нажимается.
5. **Защита от отправки заказа** — перехват `Fetch.enable` на весь сценарий (`requestStage: Request`):
   - на всех шагах POST / PUT / PATCH / DELETE на адреса, похожие на заказ (`order`, `checkout`, `oformlenie`,
     `zakaz`, `sale.order.ajax` …, список в `data/checkout-selectors.php`), блокируются (`Fetch.failRequest`,
     `BlockedByClient`);
   - с начала шага `checkout` блокируются **все** POST / PUT / PATCH / DELETE;
   - если нажатие «В корзину» перевело страницу на адрес оформления — сценарий сразу переходит в режим `checkout`;
   - остальные запросы — `Fetch.continueRequest`; ошибка обработчика → запрос блокируется (безопасный отказ),
     в конце сценария `Fetch.disable`;
   - лог перехвата: метод, адрес без query, шаг, `blocked` / `passed`. В лог приложения и в факты тела запросов не
     попадают.
6. **Находки** — `makeFinding('checkout', …)`, ключ fingerprint = код упавшего шага (стабилен между запусками),
   `location` — адрес шага, `page_id` — страница сценария, в `evidence`: `step`, `reason`, `screenshot_id`,
   число ошибок консоли. Свой случай — своя находка:
   - кнопка «В корзину» не найдена;
   - товар не добавился (нет сигнала после нажатия и корзина пуста);
   - корзина пуста (сигнал был, а корзина пуста);
   - форма оформления не открылась (адрес не найден / не открылся / нет формы с полями).
7. **Ошибки консоли** — только факты (число и первые `CHECKOUT_CONSOLE_SAMPLE` сообщений, обрезанных до
   `CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS`). Отдельных `js_error` / `failed_request` нет: их даёт шаг `browser`.
8. **Хранение.**
   - Страницы сценария — новые строки `checked_pages` (`page_type` `product` / `cart` / `checkout`,
     `found_by` — `manual` для `product_url`, иначе `link_heuristic`): у каждой свой `page_id`, поэтому файл
     скриншота `<diag>/<page>-1366.jpg` не перезаписывает снимок шага `browser`.
   - Скриншоты 1366 — в `screenshots` через `buildScreenshotFilePath()` / `saveScreenshotFiles()` /
     `insertScreenshot()`.
   - Итог — `summary_json.checkout`, **компактно** (не длиннее `FACT_SECTION_MAX_CHARS`, он уходит в пакет фактов
     ИИ через `packSummaryFacts()`): `steps` [{code, status, url, page_id, screenshot_id, console_errors,
     console_sample, reason}], `reached` (код последнего пройденного шага), `posts` {checkout_sent, blocked},
     `page_ids` (все страницы сценария).
9. **Страницы сценария не тратят деньги и не дублируются** (по `summary_json.checkout.page_ids`, чистая функция
   `excludeCheckoutPages()` в `CheckoutCheck.php`; у старых диагностик ничего не меняется):
   - `ai_visual` — не отправляются (картинки — самая дорогая часть ИИ);
   - `ai_problem`, `ai_summary` — не входят в `pages` пакета фактов (сценарий уже описан в
     `summary_json.checkout`);
   - отчёт — не попадают в «Проверенные страницы» и в их галереи (снимки там собираются по адресу). Находки
     по страницам (`buildReportFindingsByPage`) по-прежнему видят все страницы — находка `checkout` не теряется.
10. **Тайм-ауты** — именованные константы: `CHECKOUT_STEP_TIMEOUT_MS` (загрузка шага), `CHECKOUT_ADD_WAIT_MS`
    (сигнал после нажатия). Сумма по всем шагам заметно меньше `HEARTBEAT_STALE_SECONDS`; heartbeat — после
    каждого шага.
11. **Сообщение шага**: «Дошёл до формы оформления; POST на оформлении: ушло 0, заблокировано 2» /
    «Остановился на шаге „Корзина“: корзина пуста» / причина пропуска.

## Scope — что трогаем
- [x] `data/checkout-selectors.php` — создать: по платформам — кнопка «В корзину», счётчик / мини-корзина,
      «корзина пуста», строка товара в корзине, форма оформления, «нет в наличии», обязательные варианты;
      `generic` — общие тексты и селекторы; стоп-лист текстов кнопок; шаблоны адресов «заказа» для блокировки POST
- [x] `src/Services/CheckoutScenario.php` — создать: проход шагов в Chrome, перехват Fetch, диалоги, скриншоты,
      лог перехвата. Чистые функции отдельно от Chrome: решение о блокировке запроса, выбор селекторов по платформе,
      порядок адресов корзины / оформления, проверка текста кнопки по стоп-листу, разбор результата DOM-проверки
- [x] `src/Services/Checks/CheckoutCheck.php` — создать: результат сценария → находки / `skipped` / сообщение шага;
      `buildCheckoutSummary()` (компактный `summary_json.checkout`), `excludeCheckoutPages()`. Без БД и браузера
- [x] `src/Services/DiagnosticRunner.php` — изменить: `require_once`; шаг
      `['code' => 'checkout', 'title' => 'Сценарий заказа', …]` после `browser`; `runCheckoutStep()` (страницы,
      скриншоты, `summary_json.checkout`, heartbeat); в `runAiProblemStep`, `runAiVisualStep`, `runAiSummaryStep`
      страницы пропускаются через `excludeCheckoutPages()`
- [x] `src/Services/ReportBuilder.php` — изменить минимально: в `buildReportPages()` и `buildPageScreenshotMap()`
      передаются страницы без страниц сценария
- [x] `tests/Unit/CheckoutCheckTest.php` — создать
- [x] `tests/Unit/CheckoutScenarioTest.php` — создать (только чистые функции)
- [x] `tests/Unit/DiagnosticRunnerTest.php` — изменить: `testStepsOrder` с `checkout` после `browser`
- [x] `tests/Unit/ReportBuilderTest.php` — изменить: страницы сценария не в «Проверенных страницах» и галереях,
      находка на странице сценария видна; без `summary_json.checkout` всё как раньше

## Out of scope — не трогаем
- Карточка «Сценарий заказа» в отчёте, `checkout-steps.php`, `diagnostic.php`, `FactPacker.php` — таск 3
- `BrowserService.php`, `VisualAnalysis.php`, `ShopCrawler.php`, `data/shop-patterns.php`
- Заполнение полей формы, выбор доставки / оплаты, нажатие любых кнопок оформления
- Мобильные ширины в сценарии (только 1366)
- Находки `js_error` / `failed_request` из сценария
- Миграции БД, новые значения ENUM
- Объединение запусков Chrome шагов `browser` и `checkout` (рефакторинг)
- Таски 3–7 фазы 5

## Definition of Done
- [x] На реальном магазине (sudar.su или cleansan.ru) сценарий доходит до формы оформления; по логу перехвата на
      странице оформления не ушло ни одного POST (`posts.checkout_sent = 0`)
- [x] Каждый из случаев «кнопка не найдена», «товар не добавился», «корзина пуста», «форма не открылась» даёт свою
      находку `checkout` со `screenshot_id` (тест)
- [x] «Нет в наличии», обязательные варианты, «товар не найден», защита от ботов → `skipped` с причиной, находок
      нет (тест)
- [x] Без Chrome / без библиотеки → `skipped` с тем же текстом, что у `browser`
- [x] Блокировка (тест): на шаге `checkout` любой POST блокируется, GET проходит; до оформления POST добавления в
      корзину проходит, а POST на адрес «заказа» блокируется; кнопка «Купить в 1 клик» не выбирается
- [x] Страницы сценария не уходят в `ai_visual` и в `pages` пакета фактов `ai_problem` / `ai_summary`; в отчёте нет
      дублей снимков 1366 в «Проверенных страницах» (тест + проверка на живой диагностике)
- [x] `summary_json.checkout` не длиннее `FACT_SECTION_MAX_CHARS` (тест)
- [x] Шаг виден в прогрессе после «Проверка в браузере»; старые диагностики открываются без ошибок
- [x] Браузер закрывается и профиль удаляется при любом исходе (пусто в `storage/temp` после шага)
- [x] Проверено вживую на sudar.su, cleansan.ru, citilink.ru (у citilink ожидаемо `skipped` из-за защиты от ботов);
      время шага разумное
- [x] `composer test` проходит; в `storage/logs/app.log` нет warnings
- [x] Проверить `.docs/dod-global.md`

> Проверка 18.11.2026: Citilink в этот раз не показал защиту от ботов, поэтому сценарий дошёл до корзины и создал
> находку «нет формы оформления» на `/order/`; 52 изменяющих запроса заблокированы, ушло 0. Ветка защиты от ботов
> проверена тестом, включая защиту, появившуюся только в Chrome.

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
