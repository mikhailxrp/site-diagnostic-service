<?php

declare(strict_types=1);

/**
 * PHP-ошибки, которые сайт выводит посетителям, — по тексту HTML, без сети и БД.
 * Ошибка засчитывается только по сигнатуре PHP: «Warning: <сообщение> in <путь к файлу> on line N»
 * (или `in <путь>:N` у исключений) — обычный текст со словом «warning» находкой не становится.
 */

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';

const PHP_ERROR_CONTEXT_CHARS = 200; // символов вокруг ошибки во фрагменте `evidence`

const PHP_ERROR_PATTERN = '~(?<type>Fatal error|Parse error|Catchable fatal error|Recoverable fatal error'
    . '|Warning|Notice|Deprecated|Strict Standards)\s*:\s+(?<message>[^\r\n]+?)\s+in\s+'
    . '(?<file>(?:[A-Za-z]:)?[\\\\/][^\s:<>"\']*?\.(?:php\d?|phtml|inc|tpl))(?:\s+on\s+line\s+|:)(?<line>\d+)~u';

/** Тип ошибки → серьёзность: фатальная ломает страницу, notice/deprecated — только раскрывают путь */
const PHP_ERROR_SEVERITY = [
    'fatal error'             => 'critical',
    'parse error'             => 'critical',
    'catchable fatal error'   => 'critical',
    'recoverable fatal error' => 'critical',
    'warning'                 => 'medium',
    'notice'                  => 'low',
    'deprecated'              => 'low',
    'strict standards'        => 'low',
];

/**
 * Одинаковые ошибки (тип + файл + строка) — одна находка со счётчиком `count`.
 *
 * @return list<array> находки `php_error`
 */
function findPhpErrors(string $html, string $pageUrl, ?int $pageId = null): array
{
    $text = extractPhpErrorText($html);
    if ($text === '' || preg_match_all(PHP_ERROR_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $errors = [];
    foreach ($matches as $match) {
        $type = $match['type'][0];
        $file = $match['file'][0];
        $line = (int) $match['line'][0];
        $key = strtolower($type) . '|' . $file . '|' . $line;

        if (isset($errors[$key])) {
            $errors[$key]['count']++;
            continue;
        }

        $errors[$key] = [
            'key'      => $key,
            'type'     => $type,
            'message'  => trim($match['message'][0]),
            'file'     => $file,
            'line'     => $line,
            'count'    => 1,
            'fragment' => extractPhpErrorFragment($text, $match[0][1], strlen($match[0][0])),
        ];
    }

    return array_values(array_map(
        static fn (array $error): array => makePhpErrorFinding($error, $pageUrl, $pageId),
        $errors
    ));
}

/**
 * Текст страницы без тегов, в UTF-8 (DOM сам перекодирует windows-1251 по `<meta charset>`).
 */
function extractPhpErrorText(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $document = loadHtmlDocument($html);
    $text = $document !== null ? (string) $document->textContent : strip_tags($html);

    return mb_scrub($text, 'UTF-8');
}

/**
 * Фрагмент ±PHP_ERROR_CONTEXT_CHARS символов вокруг ошибки, пробелы схлопнуты.
 */
function extractPhpErrorFragment(string $text, int $byteOffset, int $byteLength): string
{
    $start = mb_strlen(substr($text, 0, $byteOffset));
    $length = mb_strlen(substr($text, $byteOffset, $byteLength));
    $from = max(0, $start - PHP_ERROR_CONTEXT_CHARS);

    $fragment = mb_substr($text, $from, $start - $from + $length + PHP_ERROR_CONTEXT_CHARS);

    return trim(preg_replace('/\s+/u', ' ', $fragment) ?? $fragment);
}

/**
 * @param array{key: string, type: string, message: string, file: string, line: int, count: int, fragment: string} $error
 */
function makePhpErrorFinding(array $error, string $pageUrl, ?int $pageId): array
{
    $fileName = basename(str_replace('\\', '/', $error['file']));
    $times = $error['count'] > 1 ? " Повторяется {$error['count']} раз." : '';

    return makeFinding(
        'php_error',
        "PHP {$error['type']} в {$fileName}:{$error['line']}",
        $pageUrl,
        $error['key'],
        "Сайт показывает посетителям ошибку PHP: {$error['message']}. "
            . "Раскрыт путь к файлу на сервере: {$error['file']} (строка {$error['line']}).{$times}",
        [
            'type'     => $error['type'],
            'message'  => $error['message'],
            'file'     => $error['file'],
            'line'     => $error['line'],
            'count'    => $error['count'],
            'fragment' => $error['fragment'],
        ],
        $pageId,
        PHP_ERROR_SEVERITY[strtolower($error['type'])] ?? null
    );
}
