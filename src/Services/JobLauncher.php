<?php

declare(strict_types=1);

/**
 * Запуск воркера диагностики отдельным процессом в фоне (Windows, `start /B`).
 * Вызов возвращается сразу, воркер продолжает работать после ответа сервера.
 */

const WORKER_SCRIPT_PATH = ROOT_PATH . '/bin/worker.php';

/**
 * Команда для cmd.exe: `start "" /B "<php>" "<worker>" <id> > NUL 2>&1`.
 * Пустой заголовок `""` обязателен: иначе `start` примет путь к php в кавычках за заголовок окна.
 */
function buildWorkerCommand(string $phpPath, string $scriptPath, int $diagnosticId): string
{
    if ($diagnosticId <= 0) {
        throw new InvalidArgumentException("Неверный id диагностики: {$diagnosticId}");
    }

    return sprintf(
        'start "" /B %s %s %d > NUL 2>&1',
        quoteWindowsPath($phpPath),
        quoteWindowsPath($scriptPath),
        $diagnosticId
    );
}

/**
 * Путь в двойных кавычках для cmd.exe. Кавычки и переводы строк в пути недопустимы — их не экранировать надёжно.
 */
function quoteWindowsPath(string $path): string
{
    if ($path === '' || preg_match('/["\r\n%]/', $path) === 1) {
        throw new InvalidArgumentException('Недопустимый путь для запуска воркера.');
    }

    return '"' . str_replace('/', '\\', $path) . '"';
}

/**
 * Запускает `bin/worker.php <id>` в фоне. Ошибка запуска → исключение
 * (вызывающий помечает диагностику `failed`).
 */
function launchWorker(int $diagnosticId): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Фоновый запуск воркера поддерживается только на Windows.');
    }

    if (!is_file(PHP_CLI_PATH)) {
        throw new RuntimeException('PHP CLI не найден: проверьте PHP_CLI_PATH в .env (' . PHP_CLI_PATH . ').');
    }

    $command = buildWorkerCommand(PHP_CLI_PATH, WORKER_SCRIPT_PATH, $diagnosticId);
    $process = popen($command, 'r');

    if ($process === false) {
        throw new RuntimeException('Не удалось запустить воркер диагностики.');
    }

    pclose($process);
}
