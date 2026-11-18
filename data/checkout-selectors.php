<?php

declare(strict_types=1);

/**
 * Правила сценария заказа (шаг `checkout`, CheckoutScenario.php). Только данные — пополнять без кода.
 *
 * CSS-селекторы (`platforms.<ключ>` из data/signatures.php и `generic` — для любой платформы, складываются):
 *   add_to_cart   — кнопка «В корзину» на странице товара;
 *   cart_count    — счётчик / мини-корзина в шапке (текст сравнивается до и после нажатия);
 *   cart_item     — строка товара на странице корзины;
 *   cart_empty    — блок «корзина пуста»;
 *   checkout_form — форма оформления (видимая — форма открылась);
 *   out_of_stock  — признак «нет в наличии» на странице товара;
 *   variants      — обязательный выбор вариантов (размер, цвет) — без него товар не добавить.
 * `inline_cart` у платформы — корзина и оформление во всплывающем окне на той же странице (Tilda).
 *
 * Регулярки по тексту (`generic`, PHP, видимый текст страницы):
 *   add_to_cart_texts  — текст кнопки (после приведения к нижнему регистру, `ё` → `е`): равен или начинается с;
 *   forbidden_texts    — кнопку с таким текстом сценарий не нажимает никогда (покупка в 1 клик, оформление …);
 *   forbidden_attrs    — то же по атрибутам кнопки (class, id, href, onclick …);
 *   added_texts        — сообщение «товар добавлен» после нажатия;
 *   cart_empty_texts, out_of_stock_texts — по тексту страницы.
 *
 * `requests` — адреса запросов (путь + query):
 *   order       — похожие на заказ: POST / PUT / PATCH / DELETE на них блокируются на всех шагах;
 *   add_to_cart — добавление в корзину: разрешено до оформления, даже если адрес похож на заказ
 *                 (`route=checkout/cart/add` у OpenCart, `/checkout/cart/add/` у Magento);
 *   checkout_document — открытие такой страницы (документ) переводит сценарий в режим оформления: блокируются все
 *                 изменяющие запросы.
 */

return [
    'platforms' => [
        'woocommerce' => [
            'add_to_cart'   => ['button.single_add_to_cart_button', 'form.cart button[type="submit"]'],
            'cart_count'    => ['.cart-contents .count', '.cart-contents-count', '.wc-block-mini-cart__badge'],
            'cart_item'     => ['.woocommerce-cart-form__cart-item', 'tr.cart_item', '.wc-block-cart-items__row'],
            'cart_empty'    => ['.cart-empty', '.wc-block-cart__empty-cart__title', '.wp-block-woocommerce-empty-cart-block'],
            'checkout_form' => ['form.woocommerce-checkout', 'form.checkout', '.wc-block-checkout', '.wp-block-woocommerce-checkout'],
            'out_of_stock'  => ['.stock.out-of-stock'],
            'variants'      => ['form.variations_form'],
        ],
        'bitrix' => [
            'add_to_cart'   => [
                '[id$="_add_basket_link"]',
                '[id$="_buy_link"]',
                '.product-item-detail-buy-button',
                '[data-entity="basket-button"]',
            ],
            'cart_count'    => ['.basket-line-block__count', '.bx-basket-block .bx-hdr-profile-count'],
            'cart_item'     => ['[data-entity="basket-item"]', '.basket-items-list-item-container', '.bx_ordercart_itemtitle'],
            'cart_empty'    => ['.bx-sbb-empty-cart-container'],
            'checkout_form' => ['#bx-soa-order-form', 'form[name="ORDER_FORM"]', '#bx-soa-order'],
            'out_of_stock'  => ['[id$="_not_avail"]', '.product-item-detail-info-container-not-available'],
            'variants'      => [],
        ],
        'opencart' => [
            'add_to_cart'   => ['#button-cart'],
            'cart_count'    => ['#cart-total', '#cart > button'],
            'cart_item'     => ['form[action*="checkout/cart"] tbody tr'],
            'cart_empty'    => [],
            'checkout_form' => ['#checkout-checkout', '#collapse-checkout-option', '#collapse-payment-address', '#form-checkout'],
            'out_of_stock'  => [],
            'variants'      => ['#product .required select', '#product .required input[type="radio"]'],
        ],
        'joomla' => [
            'add_to_cart'   => ['.addtocart-button', 'input[name="addtocart"]'],
            'cart_count'    => ['.vmCartModule .total_products', '#jshop_module_cart .jshop_quantity_products'],
            'cart_item'     => ['.cart-summary tr.sectiontableentry1', '.cart-summary tr.sectiontableentry2', 'table.jshop.cart tr.jshop_prod_cart'],
            'cart_empty'    => [],
            'checkout_form' => ['#checkoutForm', 'form[name="loginForm"] ~ form', '#comjshop form#payment_form'],
            'out_of_stock'  => [],
            'variants'      => [],
        ],
        'prestashop' => [
            'add_to_cart'   => ['[data-button-action="add-to-cart"]', 'button.add-to-cart'],
            'cart_count'    => ['.cart-products-count'],
            'cart_item'     => ['.cart-items .cart-item', '.cart-item'],
            'cart_empty'    => ['.cart-overview .no-items'],
            'checkout_form' => ['#checkout-personal-information-step', 'section.checkout-step', '#customer-form'],
            'out_of_stock'  => ['#product-availability .product-unavailable'],
            'variants'      => [],
        ],
        'magento' => [
            'add_to_cart'   => ['#product-addtocart-button'],
            'cart_count'    => ['.minicart-wrapper .counter-number'],
            'cart_item'     => ['#shopping-cart-table .cart.item', '.cart.item'],
            'cart_empty'    => ['.cart-empty'],
            'checkout_form' => ['#checkout', '#co-shipping-form', '.opc-wrapper'],
            'out_of_stock'  => ['.stock.unavailable'],
            'variants'      => ['#product-options-wrapper .super-attribute-select', '.swatch-opt .swatch-attribute'],
        ],
        'shopscript' => [
            'add_to_cart'   => ['.add2cart [type="submit"]', '#cart-form [type="submit"]', '.s-add-button'],
            'cart_count'    => ['.cart-count', '.s-cart-count', '.js-cart-count'],
            'cart_item'     => ['.s-cart-product', '.cart-item', '.wa-product'],
            'cart_empty'    => ['.s-cart-empty', '.wa-cart-empty'],
            'checkout_form' => ['#wa-order-form-wrapper', '.wa-order-form-wrapper', '.wa-step-contact-section'],
            'out_of_stock'  => ['.stock-none', '.s-stock-none'],
            'variants'      => [],
        ],
        'megagroup' => [
            'add_to_cart'   => ['.shop2-product-btn', '.shop-product-btn'],
            'cart_count'    => ['.gr-cart-preview-counter', '.shop2-cart-preview .amount', '.cart-preview-amount'],
            'cart_item'     => ['.shop2-cart-table tbody tr', '.cart-product', '.shop2-cart-product'],
            'cart_empty'    => [],
            'checkout_form' => ['.shop2-order-form', 'form.shop2-order', '#shop2-order-form'],
            'out_of_stock'  => ['.shop2-product-btn.notavailable'],
            'variants'      => [],
        ],
        'insales' => [
            'add_to_cart'   => ['[data-item-add]', 'form[action*="/cart_items"] [type="submit"]', '.js-buy'],
            'cart_count'    => ['[data-cart-positions-count]', '.js-cart-count', '.cart-count'],
            'cart_item'     => ['[data-item-id]', '.cart-item', '.cart-list__item'],
            'cart_empty'    => ['.cart-empty', '.js-cart-empty'],
            'checkout_form' => ['#order_form', 'form#new_order', '.co-checkout-block'],
            'out_of_stock'  => [],
            'variants'      => [],
        ],
        'tilda' => [
            'add_to_cart'   => ['.js-store-prod-popup-buy-btn', '.t-store__prod-popup__btn', '.js-store-prod-btn'],
            'cart_count'    => ['.t706__carticon-counter', '.js-carticon-counter'],
            'cart_item'     => ['.t706__product'],
            'cart_empty'    => [],
            'checkout_form' => ['.t706__orderform form', '.t706__cartwin .t-form'],
            'out_of_stock'  => [],
            'variants'      => [],
            'inline_cart'   => true,
        ],
    ],

    'generic' => [
        'add_to_cart' => [
            '[name="add-to-cart"]',
            '.add-to-cart',
            '.add_to_cart',
            '.addtocart',
            '.add2cart',
            '.js-add-to-cart',
            '.js-add-cart',
            '.js-to-cart',
            '.btn-buy',
            '.buy-btn',
            '.btn-cart',
            '.to-cart',
            '.to_cart',
            '[data-action="add-to-cart"]',
            '[data-role="add-to-cart"]',
        ],
        'cart_count' => [
            '.cart-count',
            '.cart__count',
            '.cart-counter',
            '.cart-qty',
            '.basket-count',
            '.basket__count',
            '.basket-counter',
            '.header-cart-count',
            '.mini-cart-count',
            '[data-cart-count]',
            '.js-cart-count',
            '.js-basket-count',
        ],
        'cart_item' => [
            '.cart-item',
            '.cart_item',
            '.cart__item',
            '.cart-product',
            '.basket-item',
            '.basket__item',
            '.basket-product',
            '[data-cart-item]',
            '[data-basket-item]',
        ],
        'cart_empty' => ['.cart-empty', '.empty-cart', '.cart--empty', '.cart__empty', '.basket-empty', '.basket--empty'],
        'checkout_form' => [],
        'out_of_stock'  => ['.out-of-stock', '.not-available', '.not_available', '.product-not-available'],
        'variants'      => ['form select[required]'],

        'add_to_cart_texts' => [
            '~^(?:добавить|положить)?\s*в\s+корзину~u',
            '~^купить$~u',
            '~^add\s+to\s+(?:cart|basket|bag)~',
            '~^buy(?:\s+now)?$~',
        ],
        // Всё, что может оформить или оплатить заказ, и кнопки, которые к корзине не относятся
        'forbidden_texts' => [
            '~клик~u',
            '~быстр~u',
            '~оформ~u',
            '~подтверд~u',
            '~заказ~u',
            '~оплат~u',
            '~рассроч~u',
            '~кредит~u',
            '~сообщ~u',
            '~уведом~u',
            '~подпис~u',
            '~избран~u',
            '~сравн~u',
            '~перейти~u',
            '~\bone[\s-]?click\b~',
            '~\b1[\s-]?click\b~',
            '~checkout~',
            '~\border\b~',
            '~\bpay~',
            '~wishlist~',
            '~compare~',
        ],
        'forbidden_attrs' => [
            '~one[-_]?click~',
            '~1[-_]?click~',
            '~buy1click~',
            '~(?:quick|fast)[-_]?(?:order|buy)~',
            '~checkout~',
            '~wishlist~',
            '~compare~',
            '~favou?rite~',
        ],
        'added_texts' => [
            '~добавлен\w*\s+в\s+корзину~u',
            '~товар\s+добавлен~u',
            '~в\s+корзине~u',
            '~added\s+to\s+(?:your\s+)?(?:cart|basket|bag)~',
        ],
        'cart_empty_texts' => [
            '~корзина\s+пуста~u',
            '~в\s+(?:вашей\s+)?корзине\s+(?:пока\s+)?(?:нет|ничего\s+нет)~u',
            '~cart\s+is\s+(?:currently\s+)?empty~',
            '~basket\s+is\s+(?:currently\s+)?empty~',
        ],
        'out_of_stock_texts' => [
            '~нет\s+в\s+наличии~u',
            '~товар\s+закончился~u',
            '~снят\w*\s+с\s+производства~u',
            '~out\s+of\s+stock~',
            '~sold\s+out~',
        ],
    ],

    'requests' => [
        'order' => [
            '~checkout~i',
            '~(?:^|[/_.?&=-])order~i',
            '~oformlen~i',
            '~zakaz~i',
            '~payment~i',
            '~/pay(?:/|\?|$)~i',
        ],
        'add_to_cart' => [
            '~cart/add~i',
            '~add[-_]?to[-_]?(?:cart|basket)~i',
            '~addtocart~i',
            '~add2basket~i',
            '~basket/add~i',
            '~/cart_items~i',
        ],
        'checkout_document' => [
            '~/checkout(?:/|\?|$)(?!cart)~i',
            '~route=checkout/checkout~i',
            '~/order/make~i',
            '~/oformlenie~i',
            '~/new_order~i',
            '~controller=order(?:&|$)~i',
        ],
    ],

    // Поле формы — «контактное», если в name / id / type / placeholder / autocomplete есть одно из (JS, без флагов)
    'contact_field_pattern' => 'name|phone|tel|mail|fio|surname|address|city|zip|postcode|имя|телефон|фамил|адрес|город|почт|e-mail',
];
