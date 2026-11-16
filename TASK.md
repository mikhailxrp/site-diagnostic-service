# Current Task

## Фаза
Phase 2 — Глубокая проверка и история

## Задача
`php bin/update-databases.php` скачивает Wordfence Intelligence v3 (production) и retire.js в компактные индексы;
новый шаг `vulnerabilities` «Уязвимости» (сразу после `outdated`) создаёт находки `vulnerability` по видимым
версиям ядра, плагинов и тем WordPress, JS-библиотек и по своему списку 1С-Битрикс; отчёт показывает даты баз
уязвимостей.

## Образец
Образца нет: компоненты Tabler (решение владельца, 15.11.2026). Изменение экрана отчёта:
- находка «Уязвимость»: идентификатор (CVE / БДУ / Wordfence ID), диапазон затронутых версий, исправленная версия,
  ссылка на источник — текстом в `details` и в «Сырых данных»;
- карточка «Стек», список баз: + «База уязвимостей WordPress — от …», «База уязвимостей JS — от …»; то же
  предупреждение «старше 14 дней»;
- базы нет / нет токена → сообщение шага «база уязвимостей не загружена»;
- старая диагностика без шага `vulnerabilities` → как сейчас.

## Решения (16.11.2026, подтверждены владельцем)
- **Проверено запросом 16.11.2026:**
  - Wordfence `/v3/vulnerabilities/scanner` — 88 МБ, 44 144 записи (плагины 43 056, темы 3 297, ядро 426), но
    без `cve` и `cvss` → берём **production** (`/v3/vulnerabilities/production`): там есть CVE и CVSS;
  - Wordfence отдаёт **всю базу одним запросом** (ядро, плагины, темы) — лимит 1 запрос / 30 минут не мешает:
    обновление раз в неделю = 1 запрос к Wordfence за запуск. Повторный ручной запуск раньше 30 минут →
    ответ 429, база Wordfence остаётся прошлой, остальные базы обновляются;
  - разбор 88 МБ — ~320 МБ памяти; production больше → `memory_limit` поднимается только в
    `bin/update-databases.php` (константа); не хватит — разбор по частям, без пакетов;
  - HttpClient (лимит 5 МБ, без заголовков) не подходит → отдельное скачивание сразу в файл с
    `Authorization: Bearer`;
  - retire.js `jsrepository-v4.json` — 640 КБ, обычный `httpRequest`; есть jquery (12), bootstrap (15),
    lodash (10), react (8), vue (4), jquery-ui (4), jquery-migrate (2), swiper (2); slick-carousel и fancybox нет.
    CVSS нет — серьёзность из поля `severity` (low / medium / high / critical);
- **Битрикс:** ссылки на реестр уязвимых модулей нет → `data/vulnerabilities/bitrix.json` наполняется из БДУ
  ФСТЭК (и CVE, где есть); в файл попадает только запись со ссылкой на источник. Пополняется руками.
- **Схема БД не меняется:** `step_code`, `category` — VARCHAR; `summary_json.databases` дополняется через
  `JSON_MERGE_PATCH`.
- **Сопоставление с базами:** WordPress — `core:wordpress`, плагин / тема с `parent = wordpress` → `plugin:slug` /
  `theme:slug`; JS-библиотека — ключ `retire` в `data/version-sources.php` (одно место на элемент стека).

## Scope — что трогаем
- [ ] `src/Services/HttpClient.php` — изменить: скачивание в файл с заголовками, своими тайм-аутом и лимитом
      размера (константы)
- [ ] `src/Services/VulnerabilityDatabases.php` — создать: обновление баз Wordfence и retire.js, разбор и сборка
      индексов (чистые функции, сеть параметром):
  - Wordfence → индекс по `type:slug`: id, CVE, название, диапазоны `from/to` с признаками включения границ,
    `patched_versions`, CVSS score, ссылка;
  - retire.js → индекс по библиотеке: `atOrAbove` / `below`, severity, CVE / GHSA, ссылки
- [ ] `src/Services/DatabaseUpdater.php` — изменить: две базы в `getDatabaseSources()`; дата базы — из общего
      `updated_at`, если у записей нет своих; без токена база Wordfence не обновляется (в логе «нет
      WORDFENCE_API_KEY»)
- [ ] `bin/update-databases.php` — изменить: `memory_limit` на время разбора (константа)
- [ ] `data/version-sources.php` — изменить: необязательный ключ `retire` у JS-библиотек
- [ ] `data/vulnerabilities/bitrix.json` — создать: ядро и модули Битрикса из БДУ ФСТЭК (идентификатор БДУ / CVE,
      диапазон версий, исправленная версия, ссылка, дата)
- [ ] `src/Services/VulnerabilityService.php` — создать: диапазоны версий, CVSS → серьёзность (≥ 9 `critical`,
      ≥ 7 `high`, ≥ 4 `medium`, иначе `low`), поиск по индексам
- [ ] `src/Services/Checks/VulnerabilityCheck.php` — создать: шаг без БД, находки `vulnerability`; без версии
      находки нет; модуль Битрикса из списка без версии → `info`; Shop-Script → «нет источника данных»;
      Мегагрупп, InSales, Tilda → «платформа обновляется провайдером»
- [ ] `src/Services/DiagnosticRunner.php` — изменить: шаг `vulnerabilities` после `outdated`, даты баз —
      в `summary_json.databases`
- [ ] `src/Services/SettingsService.php`, `.env.example` — изменить: `WORDFENCE_API_KEY` вместо `WPSCAN_API_KEY`
      (маской, только чтение)
- [ ] `tests/Unit/VulnerabilityServiceTest.php`, `tests/Unit/VulnerabilityCheckTest.php`,
      `tests/Unit/VulnerabilityDatabasesTest.php` — создать на маленьких фикстурах `tests/fixtures/vulnerabilities/`
- [ ] `tests/Unit/SettingsServiceTest.php`, `tests/Unit/DatabaseUpdaterTest.php`, `tests/Unit/DiagnosticRunnerTest.php`
      — дополнить, если проверяют список ключей, реестр или шаги

## Out of scope — не трогаем
- NVD + OSV для ядер Joomla, Magento, PrestaShop, OpenCart и склейка по CVE (таск 8)
- онлайн-запросы к Wordfence при диагностике — только локальная база
- бейдж «уязвима» у элемента стека и кликабельная ссылка в находке
- автообновление списка Битрикса
- `bin/schedule-update-databases.ps1` — задача Планировщика уже запускает `update-databases.php`
- рефакторинг `OutdatedCheck`, `VersionSources`

## Definition of Done
- [ ] Уязвимость содержит идентификатор (CVE / БДУ / Wordfence), диапазон затронутых версий, исправленную версию
      и ссылку на источник (тест)
- [ ] Границы диапазона: `0.9.138` при `[*, 0.9.138)` не уязвима, `0.9.137` — уязвима (тест)
- [ ] CVSS 9.8 → `critical`, 7.5 → `high`, 5.0 → `medium`, 3.1 → `low`; у retire.js — серьёзность из базы (тест)
- [ ] Без видимой версии находки `vulnerability` нет; модуль Битрикса из списка без версии → `info` «модуль из
      реестра уязвимых решений: проверить версию» (тест)
- [ ] Нет базы или нет токена → шаг не падает, сообщение «база уязвимостей не загружена» (тест)
- [ ] Shop-Script — «нет источника данных»; Мегагрупп, InSales, Tilda — «платформа обновляется провайдером» (тест)
- [ ] Сбой скачивания / битый JSON / ответ 429 не портят прошлую базу (тест на разборе)
- [ ] `php bin/update-databases.php` скачивает обе базы за один запуск (Wordfence — одним запросом); в отчёте
      новой диагностики видны их даты
- [ ] Страница настроек показывает Wordfence (маской), WPScan нет
- [ ] Проверено на сайтах владельца (sudar.su — Битрикс без версии, без ложных находок) и на сайте с WordPress
- [ ] Проверить `.docs/dod-global.md`

## Важные правила
- Следовать `CLAUDE.md`
- Работать только в рамках Scope
- Не менять файлы вне Scope
- Не рефакторить попутно
