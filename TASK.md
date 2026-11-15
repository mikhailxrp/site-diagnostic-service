# Current Task

## Фаза
Phase 2 — Глубокая проверка и история

## Задача
Новый шаг `seo` «Базовое SEO» (после `resources`, последний) проверяет robots.txt (доступность, закрыт ли сайт
целиком), sitemap.xml (из `Sitemap:` в robots или `/sitemap.xml`) и на проверенных страницах — title, description,
canonical, `noindex` / `nofollow` (meta и `X-Robots-Tag`); находки `seo`, итоги robots / sitemap — в сводке отчёта.

## Образец
Образца нет: компоненты Tabler (решение владельца, 15.11.2026). Изменение экрана отчёта:
- карточка «Сводка»: строки «robots.txt» (код ответа / «закрыт для всех роботов» / «не удалось проверить»),
  «sitemap.xml» (адрес и код), «Адресов в sitemap» (число; у индекса — «индекс из N файлов, в первом — M адресов»);
- SEO-находки — в общем списке и во вкладке «По страницам» (как есть после таска 4);
- старая диагностика без шага `seo` → этих строк нет.

## Решения (15.11.2026, владелец доверил выбор; перепроверены)
- **Серьёзности и правила — в одной таблице констант** в начале `SeoCheck.php` (`SEO_SEVERITY`,
  `SEO_PAGE_RULES`: какой тип страницы что проверяет). Поменять серьёзность или включить проверку для другого
  типа страницы — одна строка, остальной код не трогается
- **robots.txt** (`<origin>/robots.txt`, origin — итоговый адрес главной):
  - 404 или HTML вместо текста («мягкий 404») → `low` «нет robots.txt»; 5xx / нет ответа → `medium`;
  - `Disallow: /` в группе `User-agent: *`, `Yandex` или `Googlebot` → `high` с именем робота; пустой `Disallow:`
    разрешает всё; `Allow: /` в той же группе отменяет запрет; несколько `User-agent` подряд — одна группа;
  - ответила заглушка защиты от ботов (`detectBotProtection()`) → «не удалось проверить», без находки
- **sitemap**: адреса из `Sitemap:` robots, иначе `/sitemap.xml`; читается первый ответивший. Нет ни одного → `low`;
  4xx / 5xx / битый XML → `medium`; 0 `<loc>` → `low`. Сжатый (`.gz`, сигнатура gzip) распаковывается.
  Индекс (`<sitemapindex>`) → число вложенных файлов + адреса только в первом вложенном (решение владельца)
- **страницы**: главная + найденные шагом `crawl`, без заглушек (`bot_protection`) и без ответов не 2xx; одна
  страница под несколькими типами — один раз. По умолчанию (`SEO_PAGE_RULES`):
  - title / description — главная, категория, товар; корзина и оформление — нет (им можно быть пустыми);
  - нет `<title>` или пустой → `low`; одинаковый title у разных страниц → одна находка `low`, страницы —
    в `evidence.pages`; нет meta description → одна находка `low` со списком страниц;
  - canonical на другой хост (без `www.`) → `medium`; canonical на свою страницу или вариант с www — не находка;
  - `noindex` (meta robots / `X-Robots-Tag`): главная → `high`, категория / товар → `medium`;
    корзина / оформление — не находка;
  - `nofollow`: главная / категория → `medium`
- **www / без www** шаг `seo` не проверяет — находка остаётся только из Фазы 1 (`AvailabilityCheck`)
- Итоги robots / sitemap → `summary_json.seo` (`updateDiagnosticSummary()`), без миграции
- `HtmlParser` дополняется ключом `title` (текст первого `<title>` или `null`); `meta` и `links` (canonical) уже есть

## Отклонения и заметки по реализации
- Добавлена находка `sitemap_bad_loc` (`medium`, в той же таблице `SEO_SEVERITY`): записи `<loc>`, которые не
  адреса http(s). Найдено вживую: у sudar.su в sitemap-индексе `<loc>Array</loc>` — ошибка кода сайта; адреса
  считаются по первому правильному вложенному файлу (224). Регрессия `testNonUrlLocInSitemapIsFindingAndSkippedForCounting`
- Типовой `/sitemap.xml`, отдающий HTML-страницу, — «нет карты» (`low`), а не «битый XML» (`medium`)
- Обрезанный по размеру sitemap (лимит HttpClient) не разбирается как XML — адреса считаются по тегам, в отчёте
  «не меньше N (файл обрезан по размеру)»
- В `summary_json` поля со значением null не сохраняются (`JSON_MERGE_PATCH` удаляет null) — отчёт читает их через `??`
- Строки сводки выводит существующий цикл карточки «Сводка» — `diagnostic.php` не менялся
- Вживую: sudar.su — robots есть, sitemap-индекс из 2 файлов (224 адреса в первом правильном), находка «Array»;
  cleansan.ru — robots есть, sitemap `.xml.gz` из robots, 50000 адресов; citilink.ru — индекс из 26 файлов, в первом
  809 адресов. Title / description / canonical / noindex на найденных страницах у всех трёх в порядке — находок нет.
  Экран — 360 / 768 / 1280 px без горизонтальной прокрутки, консоль чистая
- Тесты — 480 OK на PHP 8.3 OpenServer (+ `SeoCheckTest` 30 тестов, фикстуры `tests/fixtures/seo/`)

## Scope — что трогаем
- [x] `src/Services/Checks/SeoCheck.php` — создать, без БД, сеть параметром:
      `runSeoCheck(array $context, callable $request, ?array $botRules = null): array`; таблица констант правил;
      разбор robots.txt (группы, `Disallow`, `Allow`, `Sitemap`), sitemap (urlset / sitemapindex, `<loc>`, gzip),
      проверки страниц, находки `seo` (`page_id` — первая страница, `evidence.pages`); факты: `robots`, `sitemap`,
      `pages`
- [x] `src/Services/HtmlParser.php` — изменить: ключ `title`
- [x] `src/Services/DiagnosticRunner.php` — изменить: шаг `seo` («Базовое SEO») после `resources`,
      `runSeoStep()`, итоги — в `summary_json.seo`
- [x] `src/Services/ReportBuilder.php` — изменить: строки «robots.txt», «sitemap.xml», «Адресов в sitemap» в
      `buildReportSummary()`
- [x] `src/Views/pages/diagnostic.php` — не понадобилось: строки сводки выводятся общим циклом, файл не тронут
- [x] `tests/Unit/SeoCheckTest.php` — создать; `request` подменяется заглушкой по фикстурам
- [x] `tests/fixtures/seo/` — создать: robots.txt (обычный, `Disallow: /` для всех, для Yandex, с `Allow: /`,
      с `Sitemap:`), sitemap (urlset, sitemapindex, пустой, битый, gzip), страницы (noindex, чужой canonical,
      без title / description)
- [x] `tests/Unit/HtmlParserTest.php`, `tests/Unit/DiagnosticRunnerTest.php`, `tests/Unit/ReportBuilderTest.php` —
      дополнить (`title`; порядок шагов; строки сводки)

## Out of scope — не трогаем
- Устаревшие версии и уязвимости (таски 6–8), скорость и PageSpeed (Фаза 3)
- Обход всего sitemap и проверка его адресов на 404; длина title / description, `h1`, микроразметка, hreflang
- Проверка www / без www (есть в `AvailabilityCheck`, Фаза 1)
- `ShopCrawler`, `ResourceCheck`, `BotProtectionCheck` (кроме вызова `detectBotProtection()`), `HttpClient`,
  схема БД, рефакторинг
- Лимиты числа запросов (решение владельца)

## Definition of Done
- [x] `noindex` на главной (meta или `X-Robots-Tag`) или `Disallow: /` для всех → находка `high` (тест)
- [x] `Disallow: /` только для Yandex → `high` с именем робота; `Disallow: /admin/`, пустой `Disallow:` и
      `Disallow: /` + `Allow: /` → находки нет (тест)
- [x] Пустой или одинаковый title на разных страницах → находка `low`; одинаковый — одна находка со списком
      страниц (тест)
- [x] canonical на чужой домен → `medium`; на свою страницу или вариант с www → находки нет (тест)
- [x] sitemap из `Sitemap:` robots используется вместо `/sitemap.xml`; индекс → число файлов и адреса первого;
      gzip читается; битый XML → `medium`; нет sitemap → `low` (тест)
- [x] robots.txt — заглушка защиты от ботов → «не удалось проверить», без находки (тест)
- [x] Корзина / оформление с `noindex` и без title — без находок; страница с ответом 404 не проверяется (тест)
- [x] Серьёзность меняется правкой одной константы — тест читает её из `SEO_SEVERITY`, а не из литерала
- [x] Находка «www и без www — разные сайты» из Фазы 1 не дублируется: шаг `seo` www не проверяет (тест)
- [x] В «Сводке» строки robots.txt / sitemap.xml / число адресов; у старой диагностики без `seo` их нет
      (тест ReportBuilder + браузер)
- [x] Экран соответствует описанию в «Образце» по расположению и составу; только компоненты Tabler без своих стилей;
      проверено на 360 / 768 / 1280 px
- [x] Вживую: sudar.su, cleansan.ru, citilink.ru — robots / sitemap найдены или причина понятна, находки
      правдоподобны, воркер не падает
- [x] `composer test` проходит (PHP 8.3 OpenServer), `app.log` без ошибок, консоль браузера чистая
- [x] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
