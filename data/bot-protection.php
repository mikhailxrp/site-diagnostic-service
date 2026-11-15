<?php

declare(strict_types=1);

/**
 * Признаки страниц-заглушек защиты от ботов (BotProtectionCheck.php). Только данные — пополнять без кода.
 * Все признаки — подстроки без учёта регистра.
 *
 * `providers` — по защите (проверяются по порядку):
 *   title     — название для отчёта;
 *   headers   — имя заголовка ответа (в нижнем регистре) → подстроки значения: заглушка сразу;
 *   html      — подстроки HTML, которые бывают только на заглушке: заглушка сразу;
 *   thin_html — подстроки, которые бывают и на настоящих страницах (скрипт защиты, капча в форме):
 *               заглушка, только если на странице почти нет текста (`thin_page_max_text`);
 *   thin_markup — то же, но ищется в разметке без содержимого `<script>` / `<style>`: виджет капчи на странице,
 *               а не скрипт капчи, который сайт подключает везде (у sudar.su — и на пустой корзине).
 * Заголовков CDN (`Server: ddos-guard`, `cf-ray`) и кода 403/503 мало: так отвечают и настоящие страницы.
 */

return [
    'thin_page_max_text' => 600, // символов видимого текста (без скриптов и стилей); у заглушек — меньше 300

    'providers' => [
        'cloudflare' => [
            'title'     => 'Cloudflare',
            'headers'   => ['cf-mitigated' => ['challenge']],
            'html'      => [
                'window._cf_chl_opt',
                '<title>Just a moment...</title>',
                'Attention Required! | Cloudflare',
                'cf-browser-verification',
            ],
            'thin_html' => ['/cdn-cgi/challenge-platform/', 'challenges.cloudflare.com'],
        ],
        'ddos-guard' => [
            'title'     => 'DDoS-Guard',
            'headers'   => [],
            'html'      => ['<title>DDoS-Guard</title>', '/.well-known/ddos-guard/check?context=free_splash'],
            'thin_html' => ['/.well-known/ddos-guard/', 'ddos-guard'],
        ],
        'qrator' => [
            'title'     => 'Qrator',
            'headers'   => [],
            'html'      => ['/__qrator/qauth'],
            'thin_html' => ['qrator'],
        ],
        'captcha' => [
            'title'     => 'капча',
            'headers'   => [],
            'html'      => ['/showcaptcha?', '/checkcaptcha?'],
            'thin_markup' => ['smart-captcha', 'g-recaptcha', 'h-captcha', 'cf-turnstile', 'captcha'],
        ],
        'generic' => [
            'title'     => 'защита от ботов',
            'headers'   => [],
            'html'      => [],
            'thin_html' => [
                'Checking your browser',
                'Please enable JavaScript and cookies to continue',
                'Проверка браузера',
                'Подождите, идёт проверка',
            ],
        ],
    ],
];
