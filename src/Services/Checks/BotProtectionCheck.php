<?php

declare(strict_types=1);

/**
 * Страница-заглушка защиты от ботов (Cloudflare challenge, DDoS-Guard, Qrator, капча) — по заголовкам и HTML
 * ответа, без сети и БД. Признаки — `data/bot-protection.php`. Заглушка — это не настоящая страница сайта:
 * её ресурсы и ошибки не проверяются, в отчёте она «не удалось проверить».
 */

const BOT_PROTECTION_FILE = ROOT_PATH . '/data/bot-protection.php';
const BOT_PROTECTION_DEFAULT_THIN_TEXT = 600;

/**
 * @param array{headers?: array<string, list<string>>, body?: string} $response ответ как у `httpRequest()`
 * @param array<string, mixed>|null $rules null — из `data/bot-protection.php`
 * @return array{provider: string, title: string, reason: string}|null null — обычная страница
 */
function detectBotProtection(array $response, ?array $rules = null): ?array
{
    $rules ??= loadBotProtectionRules();
    $providers = is_array($rules['providers'] ?? null) ? $rules['providers'] : [];
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    $html = (string) ($response['body'] ?? '');

    foreach ($providers as $key => $provider) {
        foreach ($provider['headers'] ?? [] as $name => $needles) {
            foreach ($headers[$name] ?? [] as $value) {
                $needle = findBotProtectionNeedle((string) $value, $needles);
                if ($needle !== null) {
                    return makeBotProtection((string) $key, $provider, "заголовок {$name}: {$value}");
                }
            }
        }

        $needle = findBotProtectionNeedle($html, $provider['html'] ?? []);
        if ($needle !== null) {
            return makeBotProtection((string) $key, $provider, "в HTML страницы: «{$needle}»");
        }
    }

    $maxText = (int) ($rules['thin_page_max_text'] ?? BOT_PROTECTION_DEFAULT_THIN_TEXT);
    if (trim($html) === '' || mb_strlen(extractVisibleText($html)) > $maxText) {
        return null;
    }

    $markup = removeScriptsAndStyles($html);
    foreach ($providers as $key => $provider) {
        $needle = findBotProtectionNeedle($html, $provider['thin_html'] ?? [])
            ?? findBotProtectionNeedle($markup, $provider['thin_markup'] ?? []);
        if ($needle !== null) {
            return makeBotProtection((string) $key, $provider, "почти пустая страница с «{$needle}»");
        }
    }

    return null;
}

/**
 * HTML без содержимого `<script>`, `<style>`, `<noscript>`, `<template>` (вместе с тегами).
 * Без флага `u`: страница может быть в windows-1251; при ошибке регулярки HTML возвращается как есть.
 */
function removeScriptsAndStyles(string $html): string
{
    return preg_replace('~<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>~is', ' ', $html) ?? $html;
}

/**
 * @return array<string, mixed>
 */
function loadBotProtectionRules(): array
{
    $rules = require BOT_PROTECTION_FILE;
    if (!is_array($rules)) {
        throw new RuntimeException('Файл признаков защиты от ботов повреждён: ' . BOT_PROTECTION_FILE);
    }

    return $rules;
}

/**
 * Первая подстрока из списка, которая есть в тексте (без учёта регистра).
 *
 * @param list<string> $needles
 */
function findBotProtectionNeedle(string $haystack, array $needles): ?string
{
    if ($haystack === '') {
        return null;
    }

    foreach ($needles as $needle) {
        if ($needle !== '' && stripos($haystack, (string) $needle) !== false) {
            return (string) $needle;
        }
    }

    return null;
}

/**
 * Видимый текст страницы: без скриптов, стилей и тегов, пробелы схлопнуты.
 */
function extractVisibleText(string $html): string
{
    $text = html_entity_decode(strip_tags(removeScriptsAndStyles($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('~\s+~', ' ', $text) ?? $text);
}

/**
 * @param array<string, mixed> $provider
 * @return array{provider: string, title: string, reason: string}
 */
function makeBotProtection(string $key, array $provider, string $reason): array
{
    return ['provider' => $key, 'title' => (string) ($provider['title'] ?? $key), 'reason' => $reason];
}
