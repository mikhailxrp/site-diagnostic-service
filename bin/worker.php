<?php

declare(strict_types=1);

/**
 * Воркер диагностики: выполняет шаги одной диагностики.
 *
 * Запуск только из консоли (обычно через JobLauncher, в фоне):
 *   "C:/OSPanel/modules/PHP-8.3/PHP/php.exe" bin/worker.php <id>        — полная диагностика
 *   "C:/OSPanel/modules/PHP-8.3/PHP/php.exe" bin/worker.php <id> --ai   — перезапуск только ИИ-шагов
 *
 * Повторный запуск для диагностики не в статусе `queued` ничего не делает.
 * HTML-обработчики ошибок (registerAppErrorHandlers) здесь не подключаются.
 */

const EXIT_OK          = 0;
const EXIT_ERROR       = 1;
const EXIT_INVALID_ARG = 2;

if (PHP_SAPI !== 'cli') {
    echo "Воркер запускается только из консоли: php bin/worker.php <id> [--ai]\n";
    exit(EXIT_ERROR);
}

$rawId   = $argv[1] ?? '';
$rawFlag = $argv[2] ?? null;
if (preg_match('/^[1-9][0-9]{0,9}$/D', $rawId) !== 1 || count($argv) > 3) {
    fwrite(STDERR, "Укажите id диагностики — целое число больше 0: php bin/worker.php <id> [--ai]\n");
    exit(EXIT_INVALID_ARG);
}
$diagnosticId = (int) $rawId;

require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/JobLauncher.php';

if ($rawFlag !== null && $rawFlag !== WORKER_AI_ONLY_FLAG) {
    fwrite(STDERR, "Неизвестный флаг {$rawFlag}: допустим только " . WORKER_AI_ONLY_FLAG . "\n");
    exit(EXIT_INVALID_ARG);
}
$isAiOnly = $rawFlag === WORKER_AI_ONLY_FLAG;

set_time_limit(0);
ignore_user_abort(true);

set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    $isRun = $isAiOnly ? runAiRerun($diagnosticId) : runDiagnostic($diagnosticId);
    if (!$isRun) {
        fwrite(STDERR, "Диагностика {$diagnosticId} не найдена или уже не в очереди — пропуск.\n");
        exit(EXIT_ERROR);
    }
} catch (Throwable $e) {
    logException($e, ['script' => 'bin/worker.php', 'diagnostic_id' => $diagnosticId, 'ai_only' => $isAiOnly]);

    try {
        finishDiagnostic($diagnosticId, 'failed', truncateStepMessage('Ошибка воркера: ' . $e->getMessage()));
    } catch (Throwable $finishError) {
        logException($finishError, ['script' => 'bin/worker.php', 'diagnostic_id' => $diagnosticId]);
    }

    fwrite(STDERR, "Ошибка воркера. Подробности: storage/logs/app.log\n");
    exit(EXIT_ERROR);
}

exit(EXIT_OK);
