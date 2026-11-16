<?php

declare(strict_types=1);

/**
 * Правила определения стека — только данные, без кода. Пополняется без правки `StackDetector.php`.
 *
 * Все шаблоны — регулярные выражения PCRE с разделителем `~`.
 *
 * `platforms` — CMS и платформы (и плагины-платформы вроде WooCommerce):
 *   name, category (`cms` | `plugin`), plugin_slug (у плагина-платформы: slug в путях CMS-родителя);
 *   признаки: headers (имя в нижнем регистре → шаблон значения), cookies (шаблоны имени cookie),
 *   generator (мета `generator`, группа 1 — версия), urls (адреса скриптов/стилей/ссылок/картинок),
 *   html (весь HTML); version_urls (адрес, группа 1 — версия ядра);
 *   themes / plugins (адрес, группа 1 — имя), theme_ignore / plugin_ignore (шаблоны имён, которые не тема/плагин);
 *   asset_versions — `?ver=` у файлов темы/плагина — это их версия (так делает WordPress; у Битрикса
 *   `?ver=` — версия отдельного файла, поэтому флага нет);
 *   probes — служебные файлы для версии (только для найденной CMS): path от корня сайта, version — шаблон
 *   (группа 1 — версия) по ответу.
 *   fingerprints — имя отпечатков в data/fingerprints/ (`<имя>.json`: путь файла → md5 → версии релизов); файлы
 *   запрашиваются для найденной CMS без версии после probes, версия — только если совпадение однозначно.
 *
 * `server_names` — первое слово заголовка `Server` (нижний регистр) → название;
 * `cdn_server_values` — значения `Server`, которые означают CDN / защиту, а не веб-сервер;
 * `cdn_headers` — заголовок-признак → название CDN.
 *
 * `libraries` — JS/CSS-библиотеки: files — шаблоны пути файла (без строки запроса), группа 1 — версия.
 *
 * `analytics` — счётчики: patterns — шаблоны по HTML.
 */

return [
    'platforms' => [
        'wordpress' => [
            'name'      => 'WordPress',
            'category'  => 'cms',
            'headers'   => [
                'link'       => '~rel="?https://api\.w\.org/~i',
                'x-pingback' => '~/xmlrpc\.php~i',
            ],
            'cookies'   => ['~^wordpress_~', '~^wp-settings-~'],
            'generator' => '~^WordPress\s*(\d+(?:\.\d+)+)?~i',
            'urls'      => ['~/wp-content/~', '~/wp-includes/~'],
            'html'      => ['~wp-json~', '~wp-emoji-release\.min\.js~'],
            'version_urls' => ['~/wp-includes/(?:css/|js/(?!jquery/))[^?#]*\?(?:[^#]*&)?ver=(\d+(?:\.\d+)+)~'],
            'themes'    => ['~/wp-content/themes/([^/?#]+)/~'],
            'plugins'   => ['~/wp-content/plugins/([^/?#]+)/~'],
            'asset_versions' => true,
            'probes'    => [
                ['path' => 'feed/', 'version' => '~wordpress\.org/\?v=(\d+(?:\.\d+)+)~i'],
                ['path' => 'readme.html', 'version' => '~Version\s+(\d+(?:\.\d+)+)~i'],
            ],
        ],
        'woocommerce' => [
            'name'        => 'WooCommerce',
            'category'    => 'plugin',
            'plugin_slug' => 'woocommerce',
            'cookies'     => ['~^woocommerce_~', '~^wp_woocommerce_session_~'],
            'generator'   => '~^WooCommerce\s*(\d+(?:\.\d+)+)?~i',
            'urls'        => ['~/wp-content/plugins/woocommerce/~'],
            'html'        => ['~class="[^"]*\bwoocommerce\b~', '~wc_add_to_cart_params~'],
        ],
        'bitrix' => [
            'name'     => '1С-Битрикс',
            'category' => 'cms',
            'headers'  => [
                'x-powered-cms'      => '~Bitrix~i',
                'x-bitrix-composite' => '~.~',
            ],
            'cookies'  => ['~^BITRIX_SM_~'],
            'urls'     => ['~/bitrix/(?:js|templates|cache|components|panel)/~'],
            'html'     => ['~\bBX\.(?:setJSList|setCSSList|message)\(~', '~/bitrix/js/~'],
            'themes'   => ['~/(?:bitrix|local)/templates/([^/?#]+)/~'],
            'theme_ignore'  => ['~^\.default$~'],
            'plugins'  => ['~/(?:bitrix|local)/components/([^/?#]+)/~'],
            'plugin_ignore' => ['~^bitrix$~'],
        ],
        'opencart' => [
            'name'     => 'OpenCart',
            'category' => 'cms',
            'cookies'  => ['~^OCSESSID$~'],
            'urls'     => ['~/catalog/view/theme/~', '~/catalog/view/javascript/~'],
            'html'     => ['~index\.php\?route=(?:product|common|checkout|account|information)/~'],
            'themes'   => ['~/catalog/view/theme/([^/?#]+)/~'],
            'plugins'  => ['~/extension/([^/?#]+)/catalog/~'],
            'plugin_ignore' => ['~^opencart$~'],
            'fingerprints'  => 'opencart',
        ],
        'joomla' => [
            'name'      => 'Joomla',
            'category'  => 'cms',
            'generator' => '~^Joomla!?(?:\s*-\s*Open Source Content Management)?\s*(\d+(?:\.\d+)+)?~i',
            'urls'      => ['~/media/(?:jui|system|vendor|templates)/~', '~/components/com_~', '~/modules/mod_~'],
            'html'      => ['~index\.php\?option=com_~'],
            'themes'    => ['~(?<!/bitrix)(?<!/local)(?<!/media)/templates/([^/?#]+)/~', '~/media/templates/site/([^/?#]+)/~'],
            'theme_ignore'  => ['~^system$~'],
            'plugins'   => ['~/components/(com_[A-Za-z0-9_]+)/~', '~/modules/(mod_[A-Za-z0-9_]+)/~'],
            'probes'    => [
                [
                    'path'    => 'administrator/manifests/files/joomla.xml',
                    'version' => '~<version>\s*(\d+(?:\.\d+)+)\s*</version>~i',
                ],
            ],
        ],
        'prestashop' => [
            'name'      => 'PrestaShop',
            'category'  => 'cms',
            'generator' => '~^PrestaShop\s*(\d+(?:\.\d+)+)?~i',
            'cookies'   => ['~^PrestaShop-~'],
            'urls'      => ['~/modules/ps_[a-z_]+/~', '~(?<!/wp-content)(?<!/shop)/themes/[^/?#]+/assets/(?:css|js)/~'],
            'html'      => ['~\bvar\s+prestashop\s*=~', '~\bprestashop\.(?:on|emit)\(~'],
            'themes'    => ['~(?<!/wp-content)(?<!/shop)(?<!/site)/themes/([^/?#]+)/~'],
            'plugins'   => ['~/modules/([^/?#]+)/~'],
        ],
        'magento' => [
            'name'     => 'Magento',
            'category' => 'cms',
            'cookies'  => ['~^mage-~', '~^form_key$~'],
            'urls'     => ['~/static/(?:version\d+/)?frontend/~', '~/skin/frontend/~'],
            'html'     => ['~text/x-magento-init~', '~data-mage-init~'],
            'themes'   => [
                '~/static/(?:version\d+/)?frontend/([^/?#]+/[^/?#]+)/~',
                '~/skin/frontend/([^/?#]+/[^/?#]+)/~',
            ],
            'plugins'  => ['~/static/(?:version\d+/)?frontend/[^/?#]+/[^/?#]+/[^/?#]+/([A-Z][A-Za-z0-9]*_[A-Za-z0-9]+)/~'],
            'plugin_ignore' => ['~^Magento_~'],
            'probes'   => [
                ['path' => 'magento_version', 'version' => '~Magento/(\d+(?:\.\d+)+)~i'],
            ],
        ],
        'shopscript' => [
            'name'      => 'Shop-Script (Webasyst)',
            'category'  => 'cms',
            'generator' => '~^(?:Shop-?Script|Webasyst)\s*(\d+(?:\.\d+)+)?~i',
            'urls'      => ['~/wa-data/public/~', '~/wa-content/~', '~/wa-apps/~'],
            'themes'    => ['~/wa-data/public/(?:shop|site)/themes/([^/?#]+)/~'],
            'plugins'   => ['~/wa-apps/shop/plugins/([^/?#]+)/~'],
        ],
        'megagroup' => [
            'name'     => 'Мегагрупп (S3)',
            'category' => 'cms',
            'cookies'  => ['~^shop2_~'],
            'urls'     => ['~/g/(?:shop2v2|templates/shop2|basestyle|s3)/~', '~/assets/site/s3/~'],
            'html'     => ['~\bshop2\.(?:init|options|on)\b~'],
            'themes'   => ['~/g/templates/shop2/[\d.]+/([^/?#]+)/~'],
            'theme_ignore' => ['~^(?:default(?:-\d+)?|global)$~'],
        ],
        'insales' => [
            'name'      => 'InSales',
            'category'  => 'cms',
            'generator' => '~^InSales~i',
            'urls'      => ['~//(?:static|assets)\.insales(?:-cdn)?\.(?:com|ru)/~', '~/served_assets/~'],
            'html'      => ['~insales-cdn\.com~i', '~\bInSalesUI\b~'],
        ],
        'tilda' => [
            'name'     => 'Tilda',
            'category' => 'cms',
            'urls'     => ['~//static\.tildacdn\.(?:com|info|pro)/~', '~\.tildacdn\.com/~'],
            'html'     => ['~data-tilda-(?:page-id|project-id)~', '~class="t-records"~'],
        ],
    ],

    'server_names' => [
        'nginx'         => 'nginx',
        'apache'        => 'Apache',
        'openresty'     => 'OpenResty',
        'litespeed'     => 'LiteSpeed',
        'microsoft-iis' => 'Microsoft IIS',
        'caddy'         => 'Caddy',
        'tengine'       => 'Tengine',
    ],

    'cdn_server_values' => [
        'cloudflare'  => 'Cloudflare',
        'ddos-guard'  => 'DDoS-Guard',
        'qrator'      => 'Qrator',
        'servicepipe' => 'ServicePipe',
        'variti'      => 'Variti',
    ],

    'cdn_headers' => [
        'cf-ray'               => 'Cloudflare',
        'x-amz-cf-id'          => 'Amazon CloudFront',
        'x-fastly-request-id'  => 'Fastly',
        'x-akamai-transformed' => 'Akamai',
        'x-sucuri-id'          => 'Sucuri',
    ],

    'libraries' => [
        'jquery' => [
            'name'  => 'jQuery',
            'files' => ['~/jquery(?:-(\d+(?:\.\d+)+))?(?:\.slim)?(?:\.min)?\.js$~i'],
        ],
        'jquery-migrate' => [
            'name'  => 'jQuery Migrate',
            'files' => ['~/jquery-migrate(?:-(\d+(?:\.\d+)+))?(?:\.min)?\.js$~i'],
        ],
        'jquery-ui' => [
            'name'  => 'jQuery UI',
            'files' => ['~/jquery-ui(?:[.-](\d+(?:\.\d+)+))?(?:\.custom)?(?:\.min)?\.(?:js|css)$~i', '~/jquery/ui/[\w.-]+\.js$~i'],
        ],
        'bootstrap' => [
            'name'  => 'Bootstrap',
            'files' => ['~/bootstrap(?:\.bundle)?(?:-(\d+(?:\.\d+)+))?(?:\.min)?\.(?:js|css)$~i'],
        ],
        'vue' => [
            'name'  => 'Vue.js',
            'files' => ['~/vue(?:\.global|\.runtime)?(?:\.prod)?(?:\.min)?\.js$~i'],
        ],
        'react' => [
            'name'  => 'React',
            'files' => ['~/react(?:-dom)?(?:\.production)?(?:\.min)?\.js$~i'],
        ],
        'swiper' => [
            'name'  => 'Swiper',
            'files' => ['~/swiper(?:-bundle)?(?:\.min)?\.(?:js|css)$~i'],
        ],
        'slick' => [
            'name'  => 'Slick',
            'files' => ['~/slick(?:-theme)?(?:\.min)?\.(?:js|css)$~i'],
        ],
        'fancybox' => [
            'name'  => 'Fancybox',
            'files' => ['~/(?:jquery\.)?fancybox(?:\.umd)?(?:\.pack)?(?:\.min)?\.(?:js|css)$~i'],
        ],
        'lodash' => [
            'name'  => 'Lodash',
            'files' => ['~/lodash(?:\.core)?(?:\.min)?\.js$~i'],
        ],
    ],

    'analytics' => [
        'yandex_metrika' => [
            'name'     => 'Яндекс Метрика',
            'patterns' => ['~mc\.yandex\.ru/(?:metrika/(?:tag|watch)\.js|watch/\d+)~', '~\bym\(\s*\d{5,}~'],
        ],
        'google_analytics' => [
            'name'     => 'Google Analytics',
            'patterns' => [
                '~googletagmanager\.com/gtag/js\?id=(?:G|UA)-[A-Z0-9-]+~',
                '~google-analytics\.com/(?:analytics|ga)\.js~',
            ],
        ],
        'google_tag_manager' => [
            'name'     => 'Google Tag Manager',
            'patterns' => ['~googletagmanager\.com/gtm\.js~', '~googletagmanager\.com/ns\.html\?id=GTM-~'],
        ],
    ],
];
