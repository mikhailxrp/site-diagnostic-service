# Phase 1 — Экспресс-диагностика

## Цель
Владелец вставляет ссылку и описание проблемы, запускает проверку и через минуту видит отчёт на экране: жив ли сайт, какой у него стек, какие ресурсы битые и есть ли PHP-ошибки на главной.

## Статус
⏳ Ожидает

> План предварительный: перепроверить через `.docs/prompts/phase-init.md` перед началом фазы.
> Дизайн: образцов нет — экраны из компонентов Tabler.

## Таски

| # | Название | Статус |
|---|----------|--------|
| 1 | Форма новой диагностики | ⏳ Ожидает |
| 2 | HTTP-клиент и проверка доступности | ⏳ Ожидает |
| 3 | Определение стека | ⏳ Ожидает |
| 4 | Битые ресурсы и PHP-ошибки | ⏳ Ожидает |
| 5 | Отчёт на экране | ⏳ Ожидает |

---

## Таск 1 — Форма новой диагностики

**Статус:** ⏳ Ожидает

**Образец:** образца нет

**Цель таска:**
Форма «ссылка + описание проблемы» создаёт диагностику и запускает воркер; некорректная ссылка не принимается.

**Что нужно создать/изменить:**
- `src/Services/UrlValidator.php` — нормализация (добавить `https://`, убрать фрагмент), только http/https, хост резолвится, запрет `localhost` и частных IP
- `src/Controllers/DiagnosticController.php` — `create`, `store` (вместо временной кнопки из фазы 0)
- `src/Views/diagnostics/create.php` — форма, ошибки полей
- `src/Models/DiagnosticModel.php` — `host`, связь `previous_id` с прошлой диагностикой этого хоста
- `tests/Unit/UrlValidatorTest.php`

**Definition of Done:**
- [ ] `shop.ru` превращается в `https://shop.ru/`, `ftp://…`, `localhost`, `192.168.x.x` отклоняются с понятной ошибкой
- [ ] После отправки — редирект на экран прогресса, воркер запущен
- [ ] Описание проблемы необязательно, ограничено 5000 символами
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 2 — HTTP-клиент и проверка доступности

**Статус:** ⏳ Ожидает

**Цель таска:**
Шаг `availability`: коды ответа, цепочки редиректов для вариантов http/https и www/без www, SSL (действует ли, до какого числа), время ответа.

**Что нужно создать/изменить:**
- `src/Services/HttpClient.php` — curl: ручное следование редиректам с записью цепочки, технический тайм-аут (константа), понятный User-Agent; паузы между запросами нет (решение владельца — без лимитов)
- `src/Services/Checks/AvailabilityCheck.php` — 4 варианта адреса, выводы (нет редиректа на https, разные сайты на www и без www)
- `src/Services/Checks/SslCheck.php` — сертификат через `stream_socket_client` + `capture_peer_cert`: срок, домен, цепочка
- `src/Models/FindingModel.php`, `src/Models/PageModel.php` + таблицы `checked_pages`, `findings` в `install.php`
- `tests/Unit/AvailabilityCheckTest.php` — разбор цепочек на заготовленных ответах

**Definition of Done:**
- [ ] В `summary_json` есть коды, цепочки редиректов, время ответа, данные сертификата
- [ ] Сертификат истёк или истекает < 14 дней → находка `ssl` с серьёзностью `critical` / `medium`
- [ ] Сайт не отвечает → находка `critical`, остальные шаги помечаются `skipped`
- [ ] Зависший сайт обрывается по тайм-ауту, шаг `failed`, воркер не висит
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 3 — Определение стека

**Статус:** ⏳ Ожидает

**Цель таска:**
Шаг `stack`: CMS и версия, сервер, PHP, CDN, JS-библиотеки, тема и плагины — у каждого признака источник и уверенность.

**Что нужно создать/изменить:**
- `data/signatures.php` — правила по CMS (WordPress/WooCommerce, 1С-Битрикс, OpenCart, Joomla, PrestaShop, Magento, Shop-Script, InSales, Tilda): заголовки, cookies, мета `generator`, пути, служебные файлы
- `src/Services/HtmlParser.php` — `DOMDocument`: мета, скрипты, стили, ссылки, картинки
- `src/Services/StackDetector.php` — применение правил, версии (`?ver=`, комментарии, `readme.html` и т. п.), темы/плагины по путям (`/wp-content/plugins/<slug>/`, `/bitrix/templates/<name>/`, `/catalog/view/theme/<name>/`)
- `src/Models/StackModel.php` + таблица `stack_items`
- `tests/Unit/StackDetectorTest.php` — фикстуры HTML в `tests/fixtures/`

**Definition of Done:**
- [ ] На фикстурах каждой CMS из списка определяется CMS, тема и плагины
- [ ] У каждого элемента стека заполнены `evidence` и `confidence`; несколько признаков → уверенность `high`
- [ ] Служебные файлы для версии запрашиваются только для найденной CMS, а не все подряд
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 4 — Битые ресурсы и PHP-ошибки

**Статус:** ⏳ Ожидает

**Цель таска:**
Шаг `resources`: на главной проверяются CSS, JS, картинки, шрифты (4xx/5xx), ищутся PHP-ошибки в выводе и mixed content.

**Что нужно создать/изменить:**
- `src/Services/Checks/ResourceCheck.php` — сбор ресурсов (в т. ч. `url()` из inline CSS), HEAD → GET при отказе, дедупликация
- `src/Services/Checks/PhpErrorCheck.php` — шаблоны `Fatal error`, `Warning`, `Notice`, `Deprecated`, `Parse error`, трассировки, `on line N`; фрагмент вокруг ошибки в `evidence`
- `src/Services/Checks/MixedContentCheck.php` — http-ресурсы на https-странице
- `src/Services/FindingFactory.php` — серьёзность по типу, `fingerprint`
- `tests/Unit/PhpErrorCheckTest.php`, `tests/Unit/ResourceCheckTest.php`

**Definition of Done:**
- [ ] Каждый битый ресурс — отдельная находка с адресом и кодом ответа
- [ ] PHP-ошибка в HTML находится, раскрытый путь к файлу на сервере попадает в `details`
- [ ] Обычный текст со словом «warning» на странице не даёт ложной находки (тест)
- [ ] Проверить `.docs/dod-global.md`

---

## Таск 5 — Отчёт на экране

**Статус:** ⏳ Ожидает

**Образец:** образца нет

**Цель таска:**
Готовая диагностика показывает отчёт: сводка доступности, стек с источниками, находки по серьёзности.

**Что нужно создать/изменить:**
- `src/Services/ReportBuilder.php` — сборка данных отчёта, группировка по серьёзности, счётчики
- `src/Views/diagnostics/report.php` — блоки: сводка, стек, находки
- `src/Views/components/finding.php`, `src/Views/components/stack-item.php`
- `src/Controllers/DiagnosticController.php` — `show` показывает отчёт для `done`
- `tests/Unit/ReportBuilderTest.php`

**Definition of Done:**
- [ ] Находки сгруппированы: критичные → низкие → информация, у каждой видно, где найдено
- [ ] У элемента стека раскрываются источники признака
- [ ] Шаги `failed` / `skipped` показаны в отчёте как «не удалось проверить»
- [ ] Пустой отчёт (нет находок) показывает «проблем не найдено», а не пустоту
- [ ] Проверить `.docs/dod-global.md`
