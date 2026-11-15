<?php

declare(strict_types=1);

/**
 * Разбор HTML страницы через `DOMDocument`: мета-теги, скрипты, стили, ссылки, картинки, inline-скрипты,
 * комментарии. Адреса — абсолютные (с учётом `<base href>`), не-http(s) (`data:`, `javascript:`) отбрасываются.
 * Битый или пустой HTML → пустые списки, без исключений и предупреждений PHP.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';

/** Префикс, по которому libxml понимает, что строка в UTF-8 (иначе кириллица ломается) */
const HTML_UTF8_HINT = '<?xml encoding="UTF-8">';

/**
 * @return array{base_url: string, meta: list<array{name: string, content: string}>, scripts: list<string>,
 *               styles: list<string>, links: list<array{rel: string, url: string}>, images: list<string>,
 *               iframes: list<string>, inline_scripts: list<string>, inline_styles: list<string>,
 *               comments: list<string>}
 */
function parseHtml(string $html, string $baseUrl): array
{
    $result = emptyParsedHtml($baseUrl);

    if (trim($html) === '') {
        return $result;
    }

    $document = loadHtmlDocument($html);
    if ($document === null) {
        return $result;
    }

    $baseUrl = detectDocumentBaseUrl($document, $baseUrl);
    $result['base_url'] = $baseUrl;
    $resolve = static fn (string $value): ?string => resolveHtmlUrl($baseUrl, $value);

    foreach ($document->getElementsByTagName('meta') as $meta) {
        $name = strtolower(trim($meta->getAttribute('name') ?: $meta->getAttribute('property')));
        if ($name !== '') {
            $result['meta'][] = ['name' => $name, 'content' => trim($meta->getAttribute('content'))];
        }
    }

    foreach ($document->getElementsByTagName('script') as $script) {
        $src = trim($script->getAttribute('src'));
        if ($src === '') {
            $code = trim($script->textContent);
            if ($code !== '') {
                $result['inline_scripts'][] = $code;
            }
            continue;
        }
        $url = $resolve($src);
        if ($url !== null) {
            $result['scripts'][] = $url;
        }
    }

    foreach ($document->getElementsByTagName('link') as $link) {
        $url = $resolve($link->getAttribute('href'));
        if ($url === null) {
            continue;
        }
        $rel = strtolower(trim($link->getAttribute('rel')));
        if (in_array('stylesheet', preg_split('/\s+/', $rel) ?: [], true)) {
            $result['styles'][] = $url;
            continue;
        }
        $result['links'][] = ['rel' => $rel, 'url' => $url];
    }

    foreach ($document->getElementsByTagName('img') as $image) {
        // data-src / data-srcset — ленивая загрузка: настоящая картинка там, в src часто заглушка
        $candidates = [
            $image->getAttribute('src'),
            ...parseSrcset($image->getAttribute('srcset')),
            $image->getAttribute('data-src'),
            ...parseSrcset($image->getAttribute('data-srcset')),
        ];
        foreach ($candidates as $candidate) {
            $url = $resolve($candidate);
            if ($url !== null) {
                $result['images'][] = $url;
            }
        }
    }

    foreach ($document->getElementsByTagName('source') as $source) {
        foreach (parseSrcset($source->getAttribute('srcset')) as $candidate) {
            $url = $resolve($candidate);
            if ($url !== null) {
                $result['images'][] = $url;
            }
        }
    }

    foreach ($document->getElementsByTagName('video') as $video) {
        $url = $resolve($video->getAttribute('poster'));
        if ($url !== null) {
            $result['images'][] = $url;
        }
    }

    foreach ($document->getElementsByTagName('iframe') as $iframe) {
        $url = $resolve($iframe->getAttribute('src'));
        if ($url !== null) {
            $result['iframes'][] = $url;
        }
    }

    foreach ($document->getElementsByTagName('style') as $style) {
        $css = trim($style->textContent);
        if ($css !== '') {
            $result['inline_styles'][] = $css;
        }
    }

    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//*[@style]') ?: [] as $element) {
        $css = trim($element->getAttribute('style'));
        if ($css !== '') {
            $result['inline_styles'][] = $css;
        }
    }

    foreach ($xpath->query('//comment()') ?: [] as $comment) {
        $text = trim($comment->textContent);
        if ($text !== '') {
            $result['comments'][] = $text;
        }
    }

    foreach (['scripts', 'styles', 'images', 'iframes'] as $key) {
        $result[$key] = array_values(array_unique($result[$key]));
    }

    return $result;
}

function emptyParsedHtml(string $baseUrl): array
{
    return [
        'base_url'       => $baseUrl,
        'meta'           => [],
        'scripts'        => [],
        'styles'         => [],
        'links'          => [],
        'images'         => [],
        'iframes'        => [],
        'inline_scripts' => [],
        'inline_styles'  => [],
        'comments'       => [],
    ];
}

/**
 * UTF-8 → подсказка кодировки для libxml; другая кодировка (windows-1251) — libxml берёт её из `<meta charset>`.
 */
function loadHtmlDocument(string $html): ?DOMDocument
{
    $document = new DOMDocument();
    $source = mb_check_encoding($html, 'UTF-8') ? HTML_UTF8_HINT . $html : $html;

    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML($source, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return $loaded ? $document : null;
}

/**
 * `<base href>` (сам может быть относительным) или адрес страницы.
 */
function detectDocumentBaseUrl(DOMDocument $document, string $pageUrl): string
{
    $base = $document->getElementsByTagName('base')->item(0);
    if ($base === null) {
        return $pageUrl;
    }

    return resolveHtmlUrl($pageUrl, $base->getAttribute('href')) ?? $pageUrl;
}

/**
 * Адрес из атрибута → абсолютный http(s)-адрес; пустой, `data:`, `javascript:`, `#якорь` → null.
 */
function resolveHtmlUrl(string $baseUrl, string $value): ?string
{
    $value = trim($value); // DOM уже раскодировал &amp; и прочие сущности

    if ($value === '' || str_starts_with($value, '#')) {
        return null;
    }

    return resolveRedirectUrl($baseUrl, $value);
}

/**
 * `a.jpg 1x, b.jpg 2x` → [`a.jpg`, `b.jpg`].
 *
 * @return list<string>
 */
function parseSrcset(string $srcset): array
{
    if (str_starts_with(trim($srcset), 'data:')) {
        return []; // запятая внутри base64 — не разделитель
    }

    $urls = [];
    foreach (explode(',', $srcset) as $candidate) {
        $url = strtok(trim($candidate), " \t\n\r");
        if ($url !== false && $url !== '') {
            $urls[] = $url;
        }
    }

    return $urls;
}
