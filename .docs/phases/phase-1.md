# Phase 1 — Экспресс-диагностика

## Цель
Владелец вставляет ссылку и описание проблемы, запускает проверку и через минуту видит отчёт на экране: жив ли сайт, какой у него стек, какие ресурсы битые и есть ли PHP-ошибки на главной.

## Статус
✅ Завершена

> План перепроверен 15.11.2026 с учётом сделанного в Фазе 0.
> Дизайн: образцов нет — экраны из стандартных компонентов Tabler (решение владельца).
> Решение владельца (15.11.2026): форма новой диагностики — на главной `/`; временная кнопка
> `POST /diagnostics/start` и заглушка `example.com` удаляются в таске 1.
> Решение владельца (15.11.2026): шага-заглушки `report` («Сборка отчёта») нет — отчёт собирается
> из БД при открытии страницы.
> Виды экранов лежат в `src/Views/pages/` (как в Фазе 0), отчёт — состояние «готово» в `pages/diagnostic.php`.
> Защита от ботов (Cloudflare и т. п.) — Фаза 2; в Фазе 1 ответ 403/503 просто попадает в факты.

**Сайты для ручной проверки (дал владелец):**
- https://sudar.su/
- https://cleansan.ru/
- https://www.citilink.ru/ — крупный магазин, возможна защита от ботов; в Фазе 1 достаточно, чтобы воркер не падал

## Таски

| # | Название | Статус |
|---|----------|--------|
| 1 | Форма новой диагностики | ✅ Завершён |
| 2 | Каркас проверок: контекст шагов, HTTP-клиент, находки | ✅ Завершён |
| 3 | Доступность и SSL | ✅ Завершён |
| 4 | Определение стека | ✅ Завершён |
| 5 | Битые ресурсы, PHP-ошибки, mixed content | ✅ Завершён |
| 6 | Отчёт на экране | ✅ Завершён |

---

## Таск 1 — Форма новой диагностики

**Статус:** ✅ Завершён

**Образец:** образца нет — форма из компонентов Tabler

**Цель таска:**
На главной `/` форма «ссылка + описание проблемы» создаёт диагностику и запускает воркер; некорректная ссылка не принимается. Временная кнопка из Фазы 0 удалена.

**Что нужно создать/изменить:**
- `src/Services/UrlValidator.php` — нормализация (добавить `https://`, убрать фрагмент, кириллический домен → punycode через `idn_to_ascii`), только http/https, хост резолвится, запрет `localhost`, частных и зарезервированных IP; `host` без `www.`
- `src/Controllers/DiagnosticController.php` — `store` вместо временного `start`
- `src/Controllers/HomeController.php` — передаёт во View ошибки полей и введённые значения
- `config/routes.php` — `POST /diagnostics`, убрать `/diagnostics/start`
- `src/Views/pages/home.php` — форма вместо заглушки, ошибки у полей
- `src/Models/DiagnosticModel.php` — поиск прошлой диагностики этого `host`, запись `previous_id`
- `tests/Unit/UrlValidatorTest.php`

**Definition of Done:**
- [x] `shop.ru` → `https://shop.ru/`, `магазин.рф` → punycode
- [x] `ftp://…`, `localhost`, `127.0.0.1`, `192.168.x.x`, несуществующий домен отклоняются с понятной ошибкой у поля
- [x] После ошибки введённые значения сохраняются в форме
- [x] Описание проблемы необязательно, не длиннее 5000 символов
- [x] После отправки — редирект на экран прогресса, воркер запущен
- [x] Повторная диагностика того же хоста получает `previous_id`
- [x] Заглушки `example.com` и маршрута `/diagnostics/start` в коде нет (`POST /diagnostics/start` → 405: путь совпадает с `GET /diagnostics/{id}`)
- [x] Частота запусков ограничена: 5 отправок за 60 с (добавлено по решению владельца)
- [x] Проверить `.docs/dod-global.md`

---

## Таск 2 — Каркас проверок: контекст шагов, HTTP-клиент, находки

**Статус:** ✅ Завершён

**Цель таска:**
У шагов есть общий контекст (диагностика + собранные факты), шаг может остановить проверку; есть HTTP-клиент и запись находок и страниц в БД. Основа для тасков 3–5.

**Что нужно создать/изменить:**
- `src/Services/DiagnosticRunner.php` — обработчик шага получает контекст и возвращает его дополненным; флаг «остановить» → остальные шаги `skipped` с причиной; заглушки, `sleep` и шаг `report` убрать
- `src/Services/HttpClient.php` — curl: ручное следование редиректам с записью цепочки, только http/https, проверка хоста на каждом редиректе (частные IP запрещены), тайм-аут и максимальный размер ответа — константы, понятный User-Agent; паузы между запросами нет (решение владельца)
- `src/Services/FindingFactory.php` — серьёзность по типу, `fingerprint` (sha1 от `category + location + ключ`)
- `src/Models/FindingModel.php`, `src/Models/PageModel.php`
- `database/install.php` — таблицы `checked_pages`, `findings` по `.docs/database.md`
- `tests/Unit/DiagnosticRunnerTest.php` (дополнить), `tests/Unit/HttpClientTest.php`, `tests/Unit/FindingFactoryTest.php`

**Definition of Done:**
- [x] Шаг остановил проверку → следующие шаги `skipped` с причиной (тест)
- [x] Относительный `Location` правильно превращается в полный адрес (тест)
- [x] Редирект на частный IP / `localhost` обрывается (тест)
- [x] `fingerprint` одинаков для одинаковой находки и разный для разных (тест)
- [x] Ответ больше предела обрывается, воркер не падает по памяти
- [x] `php database/install.php` идемпотентен
- [x] Проверить `.docs/dod-global.md`

---

## Таск 3 — Доступность и SSL

**Статус:** ✅ Завершён

**Цель таска:**
Шаг `availability`: коды ответа, цепочки редиректов для вариантов http/https и www/без www, SSL (действует ли, до какого числа), время ответа.

**Что нужно создать/изменить:**
- `src/Services/Checks/AvailabilityCheck.php` — 4 варианта адреса, выводы (нет редиректа на https, разные сайты на www и без www)
- `src/Services/Checks/SslCheck.php` — сертификат через `stream_socket_client` + `capture_peer_cert`: срок, домен, цепочка
- `src/Services/DiagnosticRunner.php` — шаг `availability`: главная в `checked_pages` (`home`, `start`), HTML главной — в контекст
- `src/Models/DiagnosticModel.php` — запись `summary_json`
- `tests/Unit/AvailabilityCheckTest.php`, `tests/Unit/SslCheckTest.php` — на заготовленных ответах

**Definition of Done:**
- [x] В `summary_json` есть коды, цепочки редиректов, время ответа, данные сертификата
- [x] Сертификат истёк → находка `ssl` `critical`, истекает < 14 дней → `medium`
- [x] Сайт не отвечает → находка `critical`, остальные шаги `skipped`
- [x] Зависший сайт обрывается по тайм-ауту — это «сайт не отвечает»: находка `critical`, остальные шаги `skipped`, без исключения, воркер не висит (решение владельца 15.11.2026: не шаг `failed`)
- [x] Проверено на сайтах владельца (sudar.su, cleansan.ru, citilink.ru)
- [x] Проверить `.docs/dod-global.md`

---

## Таск 4 — Определение стека

**Статус:** ✅ Завершён

**Цель таска:**
Шаг `stack`: CMS и версия, сервер, PHP, CDN, JS-библиотеки, тема и плагины — у каждого признака источник и уверенность.

**Что нужно создать/изменить:**
- `data/signatures.php` — правила по CMS (WordPress/WooCommerce, 1С-Битрикс, OpenCart, Joomla, PrestaShop, Magento, Shop-Script, InSales, Tilda): заголовки, cookies, мета `generator`, пути, служебные файлы; JS-библиотеки (jQuery и др.)
- `src/Services/HtmlParser.php` — `DOMDocument`: мета, скрипты, стили, ссылки, картинки
- `src/Services/StackDetector.php` — применение правил, версии (`?ver=`, комментарии, `readme.html` и т. п.), сервер / PHP / CDN по заголовкам, темы/плагины по путям (`/wp-content/plugins/<slug>/`, `/bitrix/templates/<name>/`, `/catalog/view/theme/<name>/`)
- `src/Models/StackModel.php` + таблица `stack_items` в `install.php`
- `src/Services/DiagnosticRunner.php` — шаг `stack`
- `tests/Unit/StackDetectorTest.php` — фикстуры HTML в `tests/fixtures/`
- Сделано сверх плана: `src/Services/Checks/StackCheck.php` (шаг без БД), счётчики аналитики (Метрика, GA, GTM),
  платформа «Мегагрупп (S3)» (на ней cleansan.ru)

**Definition of Done:**
- [x] На фикстурах каждой CMS из списка определяется CMS, тема и плагины
- [x] Сервер, PHP, CDN определяются по заголовкам; JS-библиотеки — с версией, если она видна
- [x] У каждого элемента стека заполнены `evidence` и `confidence`; несколько признаков → `high`
- [x] Служебные файлы для версии запрашиваются только для найденной CMS (и только если версия ещё неизвестна)
- [x] Проверено на сайтах владельца
- [x] Проверить `.docs/dod-global.md`

---

## Таск 5 — Битые ресурсы, PHP-ошибки, mixed content

**Статус:** ✅ Завершён

**Цель таска:**
Шаг `resources`: на главной проверяются CSS, JS, картинки, шрифты (4xx/5xx), ищутся PHP-ошибки в выводе и mixed content.

**Что нужно создать/изменить:**
- `src/Services/Checks/ResourceCheck.php` — сбор ресурсов (в т. ч. `url()` из inline CSS), HEAD → GET при отказе, дедупликация
- `src/Services/Checks/PhpErrorCheck.php` — шаблоны `Fatal error`, `Warning`, `Notice`, `Deprecated`, `Parse error`, трассировки, `on line N`; фрагмент вокруг ошибки в `evidence`
- `src/Services/Checks/MixedContentCheck.php` — http-ресурсы на https-странице
- `src/Services/DiagnosticRunner.php` — шаг `resources`
- `tests/Unit/PhpErrorCheckTest.php`, `tests/Unit/ResourceCheckTest.php`, `tests/Unit/MixedContentCheckTest.php`
- Сделано сверх плана: категория находок `mixed_content` (`FindingFactory.php`, `database.md`); в `HtmlParser.php` —
  `inline_styles`, `iframes`, ленивые картинки; разбор внешних CSS (`url()`, `@import` на один уровень);
  heartbeat во время шага; HEAD перепроверяется через GET при любой неудаче; запасные форматы шрифтов — `low`

**Definition of Done:**
- [x] Каждый битый ресурс — отдельная находка с адресом и кодом ответа
- [x] PHP-ошибка в HTML находится, раскрытый путь к файлу на сервере попадает в `details`
- [x] Обычный текст со словом «warning» на странице не даёт ложной находки (тест)
- [x] Проверено на сайтах владельца
- [x] Проверить `.docs/dod-global.md`

---

## Таск 6 — Отчёт на экране

**Статус:** ✅ Завершён

**Образец:** образца нет — компоненты Tabler

**Цель таска:**
Готовая диагностика показывает отчёт: сводка доступности, стек с источниками, находки по серьёзности.
Упавшая (`failed`) — частичный отчёт из того, что успело собраться (решение владельца, 15.11.2026).

**Что нужно создать/изменить:**
- `src/Services/ReportBuilder.php` — сборка данных отчёта, группировка по серьёзности, счётчики
- `src/Views/pages/diagnostic.php` — в состоянии «готово» блоки: сводка, стек, находки (вместо карточки-заглушки)
- `src/Views/components/finding.php`, `src/Views/components/stack-item.php`
- `src/Controllers/DiagnosticController.php` — `show` передаёт отчёт для `done`
- `src/Models/FindingModel.php`, `StackModel.php`, `PageModel.php` — функции чтения по диагностике
- `public/assets/js/diagnostic-progress.js` — по `done` перезагружает страницу, чтобы показать отчёт
- `tests/Unit/ReportBuilderTest.php`
- Решения при реализации: раскрытие источников и сырых данных — нативный `<details>` (работает и без JS);
  `data-result-done` → `data-result-report`; отчёт для `cancelled` не делался (статус нигде не выставляется)

**Definition of Done:**
- [x] Находки сгруппированы: критичные → низкие → информация, у каждой видно, где найдено
- [x] У элемента стека раскрываются источники признака
- [x] Шаги `failed` / `skipped` показаны в отчёте как «не удалось проверить»
- [x] Диагностика `failed` показывает частичный отчёт с пометкой «неполный»
- [x] Пустой отчёт (нет находок) показывает «Проблем не найдено», а не пустоту
- [x] Проверено на 360 / 768 / 1280 px
- [x] Проверить `.docs/dod-global.md`
