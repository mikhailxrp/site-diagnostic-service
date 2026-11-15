<?php

declare(strict_types=1);

/**
 * Правила поиска ключевых страниц магазина (шаг `crawl`, ShopCrawler.php). Только данные — пополнять без кода.
 *
 * `platforms` — по ключам платформ из data/signatures.php:
 *   cart, checkout   — типовые пути корзины и оформления (проверяются по порядку);
 *   category_link    — регулярки по адресу ссылки на категорию (путь + query);
 *   product_link     — регулярки по адресу ссылки на товар;
 *   category_link_class, product_link_class — регулярки по атрибуту class ссылки (меню, карточки товаров);
 *   js_catalog       — признаки в HTML, что каталог строит скрипт (без браузера не найти).
 * `generic` — то же для любой платформы (и для неизвестной), плюс признаки по тексту и классу ссылки.
 * `pages` — признаки страниц: товара, категории, корзины, оформления.
 * `skip_extensions` — ссылки на файлы, которые не запрашиваются.
 */

return [
    'platforms' => [
        'woocommerce' => [
            'cart'          => ['/cart/', '/korzina/'],
            'checkout'      => ['/checkout/', '/oformlenie-zakaza/'],
            'category_link' => ['~/product-category/~', '~^/shop/?$~'],
            'product_link'  => ['~/product/[^/?]+/?$~'],
        ],
        'bitrix' => [
            'cart'          => ['/personal/cart/', '/basket/', '/cart/'],
            'checkout'      => ['/personal/order/make/', '/order/make/', '/personal/order/'],
            'category_link' => ['~^/catalog/[^/?]+/?$~'],
            'product_link'  => ['~^/catalog/(?:[^/?]+/){2,}?$~', '~^/catalog/.+/[^/?]+\.html?$~', '~[?&]ELEMENT_ID=\d+~'],
        ],
        'opencart' => [
            'cart'          => ['/index.php?route=checkout/cart', '/cart/'],
            'checkout'      => ['/index.php?route=checkout/checkout', '/checkout/'],
            'category_link' => ['~route=product/category~', '~[?&]path=\d+~'],
            'product_link'  => ['~route=product/product~', '~[?&]product_id=\d+~'],
        ],
        'joomla' => [
            'cart'          => [
                '/index.php?option=com_virtuemart&view=cart',
                '/index.php?option=com_jshopping&controller=cart',
                '/cart/',
            ],
            'checkout'      => [
                '/index.php?option=com_virtuemart&view=cart&task=checkout',
                '/index.php?option=com_jshopping&controller=checkout',
                '/checkout/',
            ],
            'category_link' => ['~view=category~', '~controller=category~', '~/virtuemart/[^/?]+/?$~'],
            'product_link'  => ['~view=productdetails~', '~controller=product&task=view~', '~[?&]virtuemart_product_id=\d+~'],
        ],
        'prestashop' => [
            'cart'          => ['/cart?action=show', '/index.php?controller=cart&action=show'],
            'checkout'      => ['/order', '/index.php?controller=order'],
            'category_link' => ['~controller=category~', '~^/(?:[a-z]{2}/)?\d+-[a-z0-9-]+/?$~'],
            'product_link'  => ['~controller=product~', '~^/(?:[a-z]{2}/)?(?:[a-z0-9-]+/)?\d+-[a-z0-9-]+\.html$~'],
        ],
        'magento' => [
            'cart'          => ['/checkout/cart/'],
            'checkout'      => ['/checkout/'],
            'category_link'       => ['~/catalog/category/view/~'],
            'product_link'        => ['~/catalog/product/view/~'],
            'category_link_class' => ['~(?:^|\s)level-top(?:\s|$)~'],
            'product_link_class'  => ['~(?:^|\s)product-item-(?:link|photo)(?:\s|$)~'],
        ],
        'shopscript' => [
            'cart'          => ['/cart/', '/shop/cart/'],
            'checkout'      => ['/checkout/', '/order/', '/shop/checkout/'],
            'category_link' => ['~/category/[^?]+~'],
            'product_link'  => ['~/product/[^/?]+/?$~'],
        ],
        'megagroup' => [
            'cart'          => ['/magazin/cart', '/cart', '/shop/cart'],
            'checkout'      => ['/magazin/order', '/order', '/shop/order'],
            'category_link'      => ['~/(?:magazin|shop)/folder/~', '~/folder/[^/?]+~'],
            'product_link'       => ['~/(?:magazin|shop)/product/~', '~/product/[^/?]+~'],
            'product_link_class' => ['~(?:^|\s)shop2-product~'],
        ],
        'insales' => [
            'cart'          => ['/cart_items'],
            'checkout'      => ['/new_order'],
            'category_link' => ['~^/collection/~'],
            'product_link'  => ['~^/product/~', '~^/collection/[^/]+/product/~'],
        ],
        'tilda' => [
            'cart'          => [],
            'checkout'      => [],
            'category_link' => ['~/tproduct/~'],
            'product_link'  => ['~/tproduct/~'],
            'js_catalog'    => ['~t-store~', '~t_store_~', '~tStoreProducts~', '~js-store-grid-cont~'],
        ],
    ],

    'generic' => [
        'cart'     => ['/cart/', '/cart', '/basket/', '/korzina/', '/personal/cart/', '/checkout/cart/'],
        'checkout' => ['/checkout/', '/order/', '/oformlenie-zakaza/', '/personal/order/make/'],

        'category_link'       => ['~^/(?:catalog|katalog|category|categories|collection|collections|shop|magazin|store)(?:/[^?]*)?$~i'],
        'product_link'        => [
            '~/(?:product|products|tovar|tovary|item|goods)/[^/?]+~i',
            '~[?&](?:product_id|item_id|productId)=\d+~i',
        ],
        // «Магазины» на сайтах ретейла — обычно адреса точек, поэтому только «каталог»
        'category_link_text'  => ['~каталог~iu', '~\bcatalog\b~i'],
        'category_link_class' => ['~(?:catalog|category|menu)~i'],
        // Слабый признак: `item` / `card` встречаются в меню, поэтому только `product`
        'product_link_class'  => ['~product~i'],
        'cart_link'           => ['~/(?:cart|basket|korzina)(?:/|$|\?)~i'],
        'cart_link_text'      => ['~корзин~iu', '~\bcart\b~i', '~\bbasket\b~i'],
        'checkout_link'       => ['~/(?:checkout|order|oformlenie)~i'],
        'checkout_link_text'  => ['~оформ\w*\s+заказ~iu', '~\bcheckout\b~i'],
        'js_catalog'          => [],
    ],

    'pages' => [
        // Сильные признаки одной карточки товара (на странице категории их обычно нет или их много)
        'product_strong' => [
            '~<meta[^>]+property=["\']og:type["\'][^>]+content=["\'](?:og:)?product~i',
            '~<meta[^>]+content=["\'](?:og:)?product["\'][^>]+property=["\']og:type["\']~i',
        ],
        // Разметка товара: засчитывается, если на странице она одна
        'product_markup' => [
            '~"@type"\s*:\s*"Product"~',
            '~itemtype=["\']https?://schema\.org/Product["\']~i',
        ],
        // Кнопка или форма «в корзину»: засчитывается, только если адрес страницы похож на товар
        // и на ней мало ссылок на товары («купить» не берётся — оно есть почти на любой странице магазина)
        'add_to_cart'    => [
            '~add[-_]?to[-_]?cart~i',
            '~в\s+корзину~iu',
            '~name=["\']add-to-cart["\']~i',
        ],
        // Страница корзины / оформления: подтверждение, что типовой путь не «мягкий 404» с кодом 200
        'cart'           => ['~корзин~iu', '~\bcart\b~i', '~\bbasket\b~i'],
        'checkout'       => ['~оформ\w*~iu', '~\bcheckout\b~i', '~заказ~iu', '~\border\b~i'],
    ],

    'skip_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'xml', 'json',
        'zip', 'rar', '7z', 'gz', 'mp4', 'mp3', 'avi', 'mov', 'webm',
        'css', 'js', 'woff', 'woff2', 'ttf', 'eot',
    ],
];
