# Current Task

## Фаза
Phase 2 — Глубокая проверка и история

## Задача
Битые ресурсы, PHP-ошибки и mixed content проверяются на каждой найденной странице (главная, категория, товар,
корзина, оформление) с общим кэшем ресурсов; страница-заглушка защиты от ботов распознаётся по содержимому →
`blocked = 1`, «не удалось проверить», без ложных находок; в отчёте находки можно смотреть и по страницам.

## Образец
Образца нет: компоненты Tabler (решение владельца, 15.11.2026). Изменения экрана отчёта:
- карточка «Проверенные страницы»: у заблокированной страницы — бейдж «не удалось проверить» и название защиты
  («Cloudflare», «DDoS-Guard», «Qrator», «капча»);
- карточка «Находки»: вкладки Tabler (`nav-tabs`) «По серьёзности» (как сейчас) и «По страницам» (группа на каждую
  проверенную страницу, внутри — её находки; находка с несколькими страницами — в каждой группе);
- у находки, найденной на нескольких страницах, — строка «Страницы:» со списком адресов вместо одной «Страница:».

## Решения (15.11.2026, подтверждены владельцем)
- `blocked` ставится при записи страницы: в `runAvailabilityStep()` и `runCrawlStep()` через
  `insertCheckedPage([... 'blocked' => ...])` — модель это уже поддерживает, без миграции
- Признак заглушки — содержимое (`Just a moment…`, `cf-chl-`, `challenge-platform`, скрипт проверки DDoS-Guard,
  Qrator, SmartCaptcha / reCAPTCHA / hCaptcha на почти пустой странице) или заголовок `cf-mitigated: challenge`.
  Одних заголовков CDN (`Server: ddos-guard`, `cf-ray`) и кода 403/503 недостаточно — иначе ложная пометка sudar.su
- Признаки заглушек — данные в `data/bot-protection.php` (как `signatures.php` и `shop-patterns.php`: пополняются
  без правки кода; решение «сигнатуры — файлы в `data/`» из `_status.md`)
- Между страницами объединяются: битый ресурс — одна находка на адрес (`page_id` — первая страница, все —
  в `evidence.pages`); mixed content — так же; PHP-ошибка — одна находка на `fingerprint` со списком страниц
- Страницы с одним итоговым адресом (корзина = оформление, как `/checkout/` у sudar.su) проверяются один раз
- Заблокированная страница: ресурсы / PHP-ошибки / mixed content по ней не проверяются; сообщение шага —
  «Проверено страниц: N, не удалось: M; ресурсов …»

## Scope — что трогаем
- [ ] `data/bot-protection.php` — создать: по защите (`cloudflare`, `ddos-guard`, `qrator`, `captcha`) название,
      признаки в HTML, признаки в заголовках, порог «почти пустой страницы» для капчи
- [ ] `src/Services/Checks/BotProtectionCheck.php` — создать, без сети и БД:
      `detectBotProtection(array $response, ?array $rules = null): ?array` → `{provider, title, reason}` или `null`
- [ ] `src/Services/Checks/ResourceCheck.php` — изменить: `runResourceCheck()` по всем страницам из
      `facts.availability.home` и `facts.crawl.pages` (без дублей по итоговому адресу, пропуская `blocked`);
      общий кэш проверенных адресов (в т. ч. CSS и вложенные `url()`); одна находка на ресурс с `evidence.pages`;
      факты: `pages` (проверенные / пропущенные), `resources`, `limited`
- [ ] `src/Services/Checks/PhpErrorCheck.php`, `src/Services/Checks/MixedContentCheck.php` — изменить только если
      объединение по `fingerprint` удобнее держать в них (иначе — в `DiagnosticRunner`, эти файлы не трогаем)
- [ ] `src/Services/DiagnosticRunner.php` — изменить: `runAvailabilityStep()` / `runCrawlStep()` — `blocked` и
      название защиты в фактах страницы, флаг — в `checked_pages`; `addPageErrorFindings()` — PHP-ошибки и mixed
      content по всем проверенным страницам, объединение по `fingerprint`; новое сообщение шага `resources`
- [ ] `src/Services/ReportBuilder.php` — изменить: `buildReportPages()` — `blocked` → «не удалось проверить» +
      защита; `buildReportFinding()` — `pageUrls` из `evidence.pages`; новый `buildReportFindingsByPage()`;
      предупреждение в сводке учитывает `blocked` главной
- [ ] `src/Views/pages/diagnostic.php` — изменить: вкладки «По серьёзности» / «По страницам», пометка у страницы
- [ ] `src/Views/components/finding.php` — изменить: список страниц у находки
- [ ] `tests/Unit/BotProtectionCheckTest.php` — создать: Cloudflare challenge, DDoS-Guard, Qrator, капча → защита;
      обычная страница с `Server: ddos-guard` и настоящим HTML → `null`; 403 без признаков → `null`
- [ ] `tests/fixtures/bot-protection/*.html` — создать, синтетические заглушки и «настоящая страница за DDoS-Guard»
- [ ] `tests/Unit/ResourceCheckTest.php`, `tests/Unit/DiagnosticRunnerTest.php`, `tests/Unit/ReportBuilderTest.php` —
      дополнить

## Out of scope — не трогаем
- SEO найденных страниц (таск 5), устаревшие версии и уязвимости (таски 6–8)
- Обход защиты (cookie, повтор с другим User-Agent, браузер) — Фаза 3
- Определение стека по заглушке (`StackCheck` / `StackDetector`), логика поиска страниц (`ShopCrawler`) —
  кроме передачи флага `blocked` в `runCrawlStep()`
- Схема БД (`blocked` уже есть), `HttpClient.php`, `PageModel.php`, `FindingModel.php`
- Лимиты числа страниц и ресурсов (решение владельца), рефакторинг

## Definition of Done
- [ ] Один и тот же битый ресурс на 3 страницах — одна находка, `page_id` первой страницы, 3 адреса в
      `evidence.pages` (тест)
- [ ] Общий для нескольких страниц ресурс запрашивается один раз (тест: счётчик вызовов заглушки `request`)
- [ ] Одна PHP-ошибка на 2 страницах — одна находка со списком страниц; разные ошибки — разные находки (тест)
- [ ] Страница-заглушка Cloudflare challenge → `blocked = 1`, по ней нет находок `broken_resource` / `php_error` /
      `mixed_content` (тест)
- [ ] DDoS-Guard, Qrator, капча распознаются; обычная страница с `Server: ddos-guard` и 403 без признаков — нет (тест)
- [ ] Корзина и оформление с одним адресом проверяются один раз (тест)
- [ ] В отчёте у заблокированной страницы «не удалось проверить» и защита; вкладка «По страницам» группирует
      находки (тест ReportBuilder + браузер)
- [ ] Старые диагностики (только главная, находки без `evidence.pages`) открываются без ошибок
- [ ] Экран соответствует описанию в «Образце» по расположению и составу, состояния реализованы; только компоненты
      Tabler без своих стилей; проверено на 360 / 768 / 1280 px, зоны нажатия от 44 px
- [ ] Вживую: sudar.su (DDoS-Guard отдаёт настоящую страницу) не помечается `blocked`, ресурсы проверены на всех
      найденных страницах; cleansan.ru — находки по страницам правдоподобны; citilink.ru — воркер не падает
- [ ] `composer test` проходит, `app.log` без ошибок, консоль браузера чистая
- [ ] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
