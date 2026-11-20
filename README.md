# Site Diagnostics

Локальный однопользовательский сервис экспресс-диагностики интернет-магазинов. По адресу сайта сервис определяет стек, собирает технические факты, проверяет страницы в headless Chrome, использует ИИ (RouterAI) для интерпретации результатов и формирует технический или клиентский отчёт, сравнение запусков и PDF.

## Стек

- PHP 8.3 (OpenServer), MySQL 8 (`MySQL-8.2`), PDO;
- собственный Mini-MVC без фреймворка;
- локальный Tabler 1.6.1 + Tabler Icons 3.52.0, Vanilla JS;
- `chrome-php/chrome` для браузерных проверок и экспорта в PDF;
- PHPUnit для тестов;
- Windows + OpenServer (`https://service-diagnostic-site.local`), локальный запуск без публикации в интернете.

## Структура

```text
public/          Document root и единственная точка входа (index.php)
src/Controllers/ Обработка HTTP-запросов (App\Controllers)
src/Models/      Работа с БД (PDO, подготовленные запросы)
src/Views/       PHP-шаблоны на Tabler (admin/layout, pages, components)
src/Services/    Диагностика, проверки (Checks/), ИИ (Ai/), браузер, отчёты, сравнение
src/Core/        Роутер, БД, запросы, логирование и общие функции
config/          Настройки, маршруты и промпты ИИ
bin/             Фоновый воркер и служебные команды
data/            Сигнатуры, селекторы и справочники
storage/         Логи, кэш, скриншоты, PDF, локальные базы и временные файлы
tests/           PHPUnit-тесты
.claude/rules/   Тематические правила кодирования (единый источник для агентов)
.docs/           PRD, схема БД, фазы, DoD, регламенты и журнал разработки
```

## Запуск и проверка

1. Настроить локальный сайт OpenServer с document root `public/` и модулем PHP 8.3 (см. `.osp/project.ini`).
2. Создать `.env` по `.env.example`.
3. Выполнить `composer install`.
4. Подготовить БД по `database/install.php` и `.docs/database.md`.
5. Запустить тесты на PHP 8.3 OpenServer (у системного `php` в `PATH` могут отсутствовать `intl` и `gd`):
   ```powershell
   & "C:\OSPanel\modules\PHP-8.3\PHP\php.exe" vendor/bin/phpunit
   ```
   (или `composer test`, если PHP 8.3 OpenServer находится первым в `PATH`).

Секреты хранятся только в `.env` и не попадают в репозиторий, отчёты, PDF или логи.

## Разработка с ИИ-агентами (Antigravity и Claude Code)

Документация настроена как единый источник правды без дублирования для обеих сред:

- `AGENTS.md` — главный регламент (загружается автоматически в Antigravity; подключается через `@AGENTS.md` в `CLAUDE.md` для Claude Code);
- `CLAUDE.md` — точка входа для Claude Code;
- `.claude/rules/general.md`, `.claude/rules/php.md`, `.claude/rules/design.md` — тематические правила (подгружаются автоматически в Claude Code по `paths:` и читаются агентом в Antigravity);
- `.docs/dod-global.md` — глобальные критерии готовности;
- `TASK.md` — контракт текущей плановой задачи (`Scope`, `Out of scope`, `DoD`);
- `.docs/phases/_status.md` — статус фаз и принятые проектные решения;
- `current-pipeline.md` — краткое описание рабочего цикла.
