<?php

declare(strict_types=1);

/**
 * Общие хелперы приложения.
 */

function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException("Файл окружения не найден: {$path}");
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim(trim($value), '"\'');
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function env(string $key, ?string $default = null): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Отсутствует переменная окружения: {$key}");
    }
    return $value;
}

function ensureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => defined('APP_ENV') && APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function closeSessionWrite(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function regenerateSession(): void
{
    ensureSessionStarted();
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}

function isAuthenticated(): bool
{
    ensureSessionStarted();
    return normalizeUserId($_SESSION['user_id'] ?? null) !== null;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        redirect('/login');
    }
}

function redirectIfAuthenticated(): void
{
    if (isAuthenticated()) {
        redirect('/dashboard');
    }
}

function setFlash(string $key, string $message): void
{
    ensureSessionStarted();
    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): ?string
{
    ensureSessionStarted();
    $flash = $_SESSION['flash'][$key] ?? null;
    if (!is_string($flash) || $flash === '') {
        return null;
    }
    unset($_SESSION['flash'][$key]);
    return $flash;
}

function normalizeUserId(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }
    return null;
}

function render(string $view, array $data = []): void
{
    if ($view === '' || str_contains($view, '..') || str_ends_with($view, '.php')) {
        throw new RuntimeException("Некорректное имя шаблона: {$view}");
    }

    $viewPath = ROOT_PATH . '/src/Views/' . trim($view, '/') . '.php';

    if (!is_file($viewPath)) {
        throw new RuntimeException("Шаблон не найден: {$view}. Ожидался путь: {$viewPath}");
    }

    extract($data, EXTR_SKIP);
    require $viewPath;
}

/**
 * Экранирует значение для вывода в HTML.
 * Принимает число и null (например, из БД): null превращается в пустую строку.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

// ─── CSRF ───────────────────────────────────────────────────────────────

function csrfToken(): string
{
    ensureSessionStarted();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

function verifyCsrfToken(mixed $token): bool
{
    ensureSessionStarted();
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    if (!is_string($sessionToken) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function requireCsrf(): void
{
    if (!verifyCsrfToken(input('_csrf'))) {
        // 403, а не 419: нестандартный 419 Apache (OpenServer) подменяет на 500
        http_response_code(403);
        exit('403 Неверный CSRF-токен. Обновите страницу и попробуйте снова.');
    }
}

// ─── Rate limiting ──────────────────────────────────────────────────────
// Файловый счётчик в storage/cache/rate-limit/ — без Redis/Memcached,
// подходит для shared-хостинга. Ключ = действие + IP клиента.

function rateLimitStoragePath(string $action): string
{
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = ROOT_PATH . '/storage/cache/rate-limit';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать директорию: {$dir}");
    }
    return $dir . '/' . sha1($action . '|' . $ip) . '.json';
}

/**
 * Атомарно проверяет лимит и засчитывает разрешённую попытку.
 * Эксклюзивная блокировка охватывает чтение, проверку и запись: параллельные запросы не могут пройти по одному
 * и тому же значению счётчика. false — лимит уже исчерпан, файл при этом не изменяется.
 */
function takeRateLimitAttempt(string $action, int $maxAttempts, int $decaySeconds = 60): bool
{
    if ($maxAttempts < 1 || $decaySeconds < 1) {
        throw new InvalidArgumentException('Лимит и время окна должны быть положительными.');
    }

    $path = rateLimitStoragePath($action);
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть файл лимита: {$path}");
    }

    $locked = false;
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Не удалось заблокировать файл лимита: {$path}");
        }
        $locked = true;

        rewind($handle);
        $content = stream_get_contents($handle);
        $existing = is_string($content) ? json_decode($content, true) : null;
        $now = time();
        $hasActiveWindow = is_array($existing)
            && isset($existing['count'], $existing['first_at'])
            && $now - (int) $existing['first_at'] <= $decaySeconds;

        if ($hasActiveWindow && (int) $existing['count'] >= $maxAttempts) {
            return false;
        }

        $data = $hasActiveWindow
            ? ['count' => (int) $existing['count'] + 1, 'first_at' => (int) $existing['first_at']]
            : ['count' => 1, 'first_at' => $now];
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException("Не удалось записать файл лимита: {$path}");
        }

        return true;
    } finally {
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

function clearRateLimit(string $action): void
{
    $path = rateLimitStoragePath($action);
    if (is_file($path)) {
        unlink($path);
    }
}
