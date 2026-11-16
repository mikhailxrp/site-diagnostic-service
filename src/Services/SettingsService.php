<?php

declare(strict_types=1);

/**
 * Логика настроек без БД: список ключей, проверка названий моделей и пути к Chrome, маскировка ключей из .env.
 */

const MODEL_NAME_MAX_LENGTH = 150; // как ai_results.model VARCHAR(150)
const MODEL_NAME_PATTERN    = '/^[A-Za-z0-9._:\/-]+$/D'; // D: $ не пропускает "\n" в конце

const SECRET_VISIBLE_PREFIX = 3;
const SECRET_VISIBLE_SUFFIX = 4;
const SECRET_SHORT_LENGTH   = 8; // ключ такой длины и короче скрывается целиком
const SECRET_FULL_MASK      = '••••••••';

/** Редактируемые настройки: ключ → подпись и подсказка */
const SETTINGS_MODEL_FIELDS = [
    'model_problem' => [
        'label' => 'Разбор проблемы',
        'hint'  => 'Гипотезы по описанию проблемы клиента.',
    ],
    'model_visual' => [
        'label' => 'Проверка вёрстки',
        'hint'  => 'Анализ скриншотов — модель должна понимать картинки.',
    ],
    'model_summary' => [
        'label' => 'Итоговый вывод',
        'hint'  => 'Сводка по всем находкам диагностики.',
    ],
];

/** Путь к Chrome: настройка `chrome_path`, пустая — путь по умолчанию */
const CHROME_PATH_SETTING    = 'chrome_path';
const CHROME_DEFAULT_PATH    = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const CHROME_PATH_MAX_LENGTH = 255;
// абсолютный путь Windows к .exe: «C:\…\chrome.exe» или «C:/…/chrome.exe»; без кавычек, <>|?* и управляющих символов
const CHROME_PATH_PATTERN    = '/^[A-Za-z]:[\\\\\/][^"<>|?*\x00-\x1F]*\.exe$/iD';

/** Переменные .env, которые показываются на странице: имя → [подпись, секрет ли] */
const SETTINGS_ENV_KEYS = [
    'ROUTERAI_BASE_URL' => ['label' => 'RouterAI: адрес API', 'secret' => false],
    'ROUTERAI_API_KEY'  => ['label' => 'RouterAI: ключ API', 'secret' => true],
    'PAGESPEED_API_KEY' => ['label' => 'Google PageSpeed Insights', 'secret' => true],
    'WORDFENCE_API_KEY' => ['label' => 'Wordfence Intelligence', 'secret' => true],
];

/**
 * @return ?string текст ошибки или null, если название корректно
 */
function validateModelName(string $name): ?string
{
    if ($name === '') {
        return 'Укажите модель.';
    }
    if (mb_strlen($name) > MODEL_NAME_MAX_LENGTH) {
        return 'Не длиннее ' . MODEL_NAME_MAX_LENGTH . ' символов.';
    }
    if (preg_match(MODEL_NAME_PATTERN, $name) !== 1) {
        return 'Допустимы латинские буквы, цифры и символы . _ : / -';
    }
    return null;
}

/**
 * Приводит ввод формы к значениям настроек и проверяет их.
 *
 * @return array{values: array<string, string>, errors: array<string, string>}
 */
function validateSettingsInput(array $input): array
{
    $values = [];
    $errors = [];

    foreach (array_keys(SETTINGS_MODEL_FIELDS) as $key) {
        $raw = $input[$key] ?? '';
        $values[$key] = is_string($raw) ? trim($raw) : '';

        $error = validateModelName($values[$key]);
        if ($error !== null) {
            $errors[$key] = $error;
        }
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Пустой путь допустим: используется CHROME_DEFAULT_PATH. Есть ли файл, проверяет «Проверить Chrome», а не форма.
 *
 * @return ?string текст ошибки или null, если путь корректен
 */
function validateChromePath(string $path): ?string
{
    if ($path === '') {
        return null;
    }
    if (mb_strlen($path) > CHROME_PATH_MAX_LENGTH) {
        return 'Не длиннее ' . CHROME_PATH_MAX_LENGTH . ' символов.';
    }
    if (preg_match(CHROME_PATH_PATTERN, $path) !== 1) {
        return 'Укажите полный путь к файлу .exe, например ' . CHROME_DEFAULT_PATH;
    }
    return null;
}

/**
 * Поле `chrome_path` из формы: обрезка пробелов и проверка.
 *
 * @return array{value: string, error: ?string}
 */
function validateBrowserSettingsInput(array $input): array
{
    $raw = $input[CHROME_PATH_SETTING] ?? '';
    $value = is_string($raw) ? trim($raw) : '';

    return ['value' => $value, 'error' => is_string($raw) ? validateChromePath($value) : 'Укажите путь строкой.'];
}

/**
 * Путь, с которым запускается Chrome: сохранённый или путь по умолчанию.
 */
function resolveChromePath(?string $saved): string
{
    $saved = trim((string) $saved);

    return $saved !== '' ? $saved : CHROME_DEFAULT_PATH;
}

/**
 * Маска секрета для показа: «sk-…a1b2». Пустой → null («не задан»).
 */
function maskSecret(string $secret): ?string
{
    if ($secret === '') {
        return null;
    }
    if (mb_strlen($secret) <= SECRET_SHORT_LENGTH) {
        return SECRET_FULL_MASK;
    }
    return mb_substr($secret, 0, SECRET_VISIBLE_PREFIX) . '…' . mb_substr($secret, -SECRET_VISIBLE_SUFFIX);
}

/**
 * Значения из .env для страницы настроек: секреты маской, адрес целиком.
 *
 * @return list<array{name: string, label: string, value: ?string}> value = null — не задан
 */
function getEnvKeysOverview(): array
{
    $overview = [];
    foreach (SETTINGS_ENV_KEYS as $name => $meta) {
        $raw = env($name, '');
        $overview[] = [
            'name'  => $name,
            'label' => $meta['label'],
            'value' => $meta['secret'] ? maskSecret($raw) : ($raw === '' ? null : $raw),
        ];
    }
    return $overview;
}
