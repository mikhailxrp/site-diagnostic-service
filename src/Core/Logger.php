<?php

declare(strict_types=1);

/**
 * Логгер приложения.
 * Пишет в storage/logs/app.log
 * Уровни: debug < info < warning < error
 */

const LOG_REDACTED          = '[REDACTED]';
const LOG_SECRET_ENV_NAMES  = ['ROUTERAI_API_KEY', 'PAGESPEED_API_KEY', 'WORDFENCE_API_KEY', 'DB_PASS'];
const LOG_SECRET_MIN_LENGTH = 8;

function logWrite(string $level, string $message, array $context = [], ?string $targetFile = null): void
{
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    $minLevel = defined('APP_LOG_LEVEL') ? strtolower(APP_LOG_LEVEL) : 'error';

    if (($levels[$level] ?? 0) < ($levels[$minLevel] ?? 3)) {
        return;
    }

    if ($targetFile === null) {
        $logDir  = defined('LOG_DIR')  ? LOG_DIR  : dirname(__DIR__, 2) . '/storage/logs';
        $logFile = defined('LOG_FILE') ? LOG_FILE : 'app.log';
        $targetFile = $logDir . '/' . $logFile;
    }

    $dir = dirname($targetFile);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }

    $safeMessage = redactKnownSecrets($message);
    $safeContext = sanitizeLogContext($context);
    $contextJson = $safeContext !== []
        ? ' | ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        : '';
    $line = sprintf("[%s] %s %s%s\n", date('c'), strtoupper($level), $safeMessage, $contextJson);

    file_put_contents($targetFile, $line, FILE_APPEND | LOCK_EX);
}

function logDebug(string $message, array $context = []): void
{
    logWrite('debug', $message, $context);
}

function logInfo(string $message, array $context = []): void
{
    logWrite('info', $message, $context);
}

function logWarning(string $message, array $context = []): void
{
    logWrite('warning', $message, $context);
}

function logError(string $message, array $context = []): void
{
    logWrite('error', $message, $context);
}

function logException(Throwable $e, array $context = [], ?string $targetFile = null): void
{
    logWrite('error', get_class($e) . ': ' . $e->getMessage(), array_merge($context, [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
    ]), $targetFile);
}

function redactKnownSecrets(string $text): string
{
    foreach (LOG_SECRET_ENV_NAMES as $name) {
        $raw = function_exists('env') ? env($name, '') : (string) ($_ENV[$name] ?? (getenv($name) ?: ''));
        $secret = trim($raw);
        if (strlen($secret) >= LOG_SECRET_MIN_LENGTH) {
            $text = str_replace($secret, LOG_REDACTED, $text);
        }
    }

    return $text;
}

function sanitizeLogContext(mixed $data): mixed
{
    $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'credential'];

    if (is_array($data)) {
        $result = [];
        foreach ($data as $k => $v) {
            $keyLower = strtolower((string) $k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($keyLower, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }
            $result[$k] = $isSensitive ? LOG_REDACTED : sanitizeLogContext($v);
        }
        return $result;
    }

    if (is_string($data)) {
        return redactKnownSecrets($data);
    }

    if ($data instanceof Throwable) {
        return redactKnownSecrets(get_class($data) . ': ' . $data->getMessage());
    }

    return $data;
}
