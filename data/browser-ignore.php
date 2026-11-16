<?php

declare(strict_types=1);

/**
 * Что из событий браузера не считается поломкой сайта (`Checks/BrowserConsoleCheck.php`). Только данные —
 * пополнять без кода. Подстроки — без учёта регистра.
 *
 *   hosts            — счётчики, реклама, виджеты: хост или его поддомен. Их запросы и исключения в их файлах
 *                      (файл исключения или верхний кадр стека) не учитываются;
 *   url_substrings   — то же по подстроке адреса (когда у хоста есть и полезные адреса: vk.com/rtrg);
 *   network_errors   — `errorText` отказов, которые вызвал сам браузер, а не сайт;
 *   blocked_reasons  — `blockedReason`, которые не поломка (mixed content — уже находка шага «Ресурсы»);
 *   resource_types   — служебные типы запросов DevTools;
 *   api_auth_statuses — коды ответа XHR / Fetch, ожидаемые без входа (запрос к API личного кабинета);
 *   statuses         — коды, которые вызвала сама проверка (три загрузки подряд упираются в лимит);
 *   log_sources      — источники `Log.entryAdded`, которые не поломка или ловятся отдельно;
 *   console_messages — подстроки сообщений консоли, которые не поломка;
 *   exception_messages — подстроки исключений и ошибок консоли, после которых страница работает (React сам
 *                      восстанавливается после несовпадения при гидратации — #418 / #423 / #425).
 */

return [
    'hosts' => [
        // Яндекс
        'mc.yandex.ru', 'mc.yandex.com', 'mc.yandex.by', 'mc.yandex.kz', 'mc.webvisor.org', 'mc.webvisor.com',
        'an.yandex.ru', 'ads.adfox.ru', 'banners.adfox.ru',
        // Google
        'google-analytics.com', 'analytics.google.com', 'googletagmanager.com', 'googleadservices.com',
        'googlesyndication.com', 'doubleclick.net', 'adservice.google.com', 'stats.g.doubleclick.net',
        // VK / Mail.ru
        'top-fwz1.mail.ru', 'top.mail.ru', 'ad.mail.ru', 'privacy-cs.mail.ru', 'ads.vk.com', 'mc.vk.com',
        // Соцсети и рекламные пиксели
        'connect.facebook.net', 'mc.facebook.com', 'ct.pinterest.com', 'analytics.tiktok.com',
        'counter.yadro.ru', 'tns-counter.ru', 'mediator.media', 'adriver.ru', 'mytarget.ru',
        // Коллтрекинг и аналитика
        'calltouch.ru', 'mod.calltouch.ru', 'roistat.com', 'cloud.roistat.com', 'comagic.ru', 'uiscom.ru',
        'mango-office.ru', 'callibri.ru', 'alloka.ru', 'hotjar.com', 'clarity.ms', 'carrotquest.io',
        'cdn.carrotquest.app', 'smartcallback.ru', 'leadhit.io', 'retailrocket.ru', 'mindbox.ru', 'flocktory.com',
        // Чаты и виджеты
        'jivosite.com', 'jivo.ru', 'code.jivo.ru', 'code.jivosite.com', 'livetex.ru', 'onicon.ru', 'envybox.io',
        'cdn-ru.bitrix24.ru', 'widget.replain.cc', 'talk-me.ru', 'verbox.ru',
    ],

    'url_substrings' => [
        'vk.com/rtrg',
        'yandex.ru/ads/',
        'yastatic.net/pcode/',
        'facebook.com/tr',
        'bitrix24.ru/b',
        'vk.com/js/api/openapi',
        'yandex.ru/metrika',
        'yandex.ru/clck',
        'google.com/ccm/collect',
        'google.com/pagead',
        'pay.yandex.ru/api/v2/badge', // бейдж «Яндекс Пэй»: Chrome без окна получает 403, обычный — не запрашивает
        '/bitrix/spread.php', // Битрикс: служебный пиксель между доменами сайта
    ],

    'network_errors' => [
        'net::ERR_BLOCKED_BY_CLIENT',
        'net::ERR_ABORTED',
        'net::ERR_CACHE_MISS',
    ],

    'blocked_reasons' => ['inspector', 'subresource-filter', 'mixed-content'],

    'resource_types' => ['Ping', 'CSPViolationReport', 'Preflight', 'SignedExchange', 'Prefetch'],

    'api_auth_statuses' => [401, 403],

    'statuses' => [429],

    'log_sources' => ['network', 'security', 'violation', 'intervention', 'recommendation', 'deprecation'],

    'console_messages' => [
        '[Report Only]',
    ],

    'exception_messages' => [
        'Minified React error #418;', // текст разметки сервера и клиента не совпал — React перерисовал сам
        'Minified React error #423;', // ошибка гидратации — корень перерисован на клиенте
        'Minified React error #425;', // текст не совпал при гидратации
        'Hydration failed because',   // то же в несжатой сборке React
    ],
];
