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

const EXIT_OK                             = 0;
const EXIT_ERROR                          = 1;
const EXIT_INVALID_ARG                    = 2;
const WORKER_SHUTDOWN_MEMORY_RESERVE_BYTES = 131072; // 128 КБ резерва под обработку OOM в shutdown-функции

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

$claimed = false;
$onClaimed = static function () use (&$claimed): void {
    $claimed = true;
};
$oomReserve = str_repeat("\0", WORKER_SHUTDOWN_MEMORY_RESERVE_BYTES);

register_shutdown_function(static function () use ($diagnosticId, &$claimed, &$oomReserve): void {
    $oomReserve = '';
    $error = error_get_last();
    if (!$claimed || !isFatalWorkerError($error)) {
        return;
    }

    handleWorkerFatalError($diagnosticId, $error);
});

try {
    $isRun = $isAiOnly ? runAiRerun($diagnosticId, $onClaimed) : runDiagnostic($diagnosticId, $onClaimed);
    if (!$isRun) {
        fwrite(STDERR, "Диагностика {$diagnosticId} не найдена или уже не в очереди — пропуск.\n");
        exit(EXIT_ERROR);
    }
} catch (DiagnosticNoLongerRunningException $e) {
    logWarning('Воркер остановлен: диагностика больше не выполняется', [
        'script'        => 'bin/worker.php',
        'diagnostic_id' => $diagnosticId,
        'ai_only'       => $isAiOnly,
    ]);
    exit(EXIT_OK);
} catch (Throwable $e) {
    logException($e, ['script' => 'bin/worker.php', 'diagnostic_id' => $diagnosticId, 'ai_only' => $isAiOnly]);

    if ($claimed) {
        try {
            $errorMessage = truncateStepMessage('Ошибка воркера: ' . $e->getMessage());
            failRunningDiagnostic($diagnosticId, $errorMessage, $errorMessage);
        } catch (Throwable $finishError) {
            logException($finishError, ['script' => 'bin/worker.php', 'diagnostic_id' => $diagnosticId]);
        }
    }

    fwrite(STDERR, "Ошибка воркера. Подробности: storage/logs/app.log\n");
    exit(EXIT_ERROR);
}

exit(EXIT_OK);
