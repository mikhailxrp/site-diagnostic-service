<?php

declare(strict_types=1);

/**
 * Шаг `checkout` без БД и браузера: результат сценария заказа (`CheckoutScenario.php`) → находки `checkout`,
 * сообщение шага и компактная сводка `summary_json.checkout`; отбор страниц сценария из списка проверенных
 * страниц (чтобы они не дублировались в отчёте и не уходили в ИИ).
 *
 * Поломка — своя находка на каждую причину; товар без наличия, с обязательными вариантами, без товара — шаг
 * пропущен, а не «сломано». Fingerprint — по главной и коду упавшего шага: выбранный товар между запусками может
 * смениться, поломка шага остаётся той же.
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/FactPacker.php';

const CHECKOUT_STEP_PRODUCT  = 'product';
const CHECKOUT_STEP_ADD      = 'add_to_cart';
const CHECKOUT_STEP_CART     = 'cart';
const CHECKOUT_STEP_CHECKOUT = 'checkout';

const CHECKOUT_STEP_LABELS = [
    CHECKOUT_STEP_PRODUCT  => 'Товар',
    CHECKOUT_STEP_ADD      => 'В корзину',
    CHECKOUT_STEP_CART     => 'Корзина',
    CHECKOUT_STEP_CHECKOUT => 'Оформление',
];

/** Тип страницы (`checked_pages.page_type`) для шага сценария */
const CHECKOUT_STEP_PAGE_TYPES = [
    CHECKOUT_STEP_PRODUCT  => 'product',
    CHECKOUT_STEP_ADD      => 'product',
    CHECKOUT_STEP_CART     => 'cart',
    CHECKOUT_STEP_CHECKOUT => 'checkout',
];

const CHECKOUT_STATUS_DONE    = 'done';
const CHECKOUT_STATUS_FAILED  = 'failed';
const CHECKOUT_STATUS_SKIPPED = 'skipped';

const CHECKOUT_OUTCOME_REACHED = 'reached'; // дошёл до формы оформления
const CHECKOUT_OUTCOME_FAILED  = 'failed';  // поломка на шаге
const CHECKOUT_OUTCOME_SKIPPED = 'skipped'; // проверить нельзя (не поломка)

const CHECKOUT_REASON_NO_PRODUCT         = 'no_product';
const CHECKOUT_REASON_BOT_PROTECTION     = 'bot_protection';
const CHECKOUT_REASON_PRODUCT_NOT_OPENED = 'product_not_opened';
const CHECKOUT_REASON_OUT_OF_STOCK       = 'out_of_stock';
const CHECKOUT_REASON_VARIANTS           = 'variants';
const CHECKOUT_REASON_ERROR              = 'scenario_error';
const CHECKOUT_REASON_BUTTON_NOT_FOUND   = 'button_not_found';
const CHECKOUT_REASON_BUTTON_DISABLED    = 'button_disabled';
const CHECKOUT_REASON_NOT_ADDED          = 'not_added';
const CHECKOUT_REASON_CART_NOT_FOUND     = 'cart_not_found';
const CHECKOUT_REASON_CART_EMPTY         = 'cart_empty';
const CHECKOUT_REASON_CHECKOUT_NOT_FOUND = 'checkout_not_found';
const CHECKOUT_REASON_NO_FORM            = 'checkout_no_form';

/**
 * Причина поломки → шаг, серьёзность, заголовок и подсказка. «Не найдено» (корзина, оформление) — `medium`: адрес
 * мог не распознать сам сценарий, это стоит проверить руками; «не добавилось», «пусто», «нет формы» — `critical`.
 */
const CHECKOUT_FAILURES = [
    CHECKOUT_REASON_BUTTON_NOT_FOUND => [
        'step'     => CHECKOUT_STEP_ADD,
        'severity' => 'high',
        'title'    => 'Сценарий заказа: на странице товара не найдена кнопка «В корзину»',
        'details'  => 'Кнопки добавления в корзину не видно (или она не распознана). Проверьте страницу товара вручную: '
            . 'если кнопки нет — купить товар нельзя.',
    ],
    CHECKOUT_REASON_BUTTON_DISABLED => [
        'step'     => CHECKOUT_STEP_ADD,
        'severity' => 'high',
        'title'    => 'Сценарий заказа: кнопка «В корзину» неактивна',
        'details'  => 'Кнопка есть, но заблокирована, а признаков «нет в наличии» на странице нет. Обычно это ошибка '
            . 'скрипта или шаблона карточки товара.',
    ],
    CHECKOUT_REASON_NOT_ADDED => [
        'step'     => CHECKOUT_STEP_ADD,
        'severity' => 'critical',
        'title'    => 'Сценарий заказа: товар не добавляется в корзину',
        'details'  => 'После нажатия «В корзину» не изменился счётчик, не было сообщения и запроса добавления, '
            . 'а корзина осталась пустой. Посмотрите ошибки консоли на этом шаге.',
    ],
    CHECKOUT_REASON_CART_NOT_FOUND => [
        'step'     => CHECKOUT_STEP_CART,
        'severity' => 'medium',
        'title'    => 'Сценарий заказа: не удалось открыть корзину',
        'details'  => 'Ни ссылка на корзину, ни типовые адреса не привели на страницу корзины. Проверьте вручную, '
            . 'как покупатель попадает в корзину.',
    ],
    CHECKOUT_REASON_CART_EMPTY => [
        'step'     => CHECKOUT_STEP_CART,
        'severity' => 'critical',
        'title'    => 'Сценарий заказа: корзина пуста после добавления товара',
        'details'  => 'Сайт показал, что товар добавлен, но в корзине его нет. Частые причины — сессия или cookie '
            . 'не сохраняются, кэш отдаёт чужую корзину, ошибка модуля корзины.',
    ],
    CHECKOUT_REASON_CHECKOUT_NOT_FOUND => [
        'step'     => CHECKOUT_STEP_CHECKOUT,
        'severity' => 'medium',
        'title'    => 'Сценарий заказа: не удалось открыть оформление заказа',
        'details'  => 'Из корзины не нашлось перехода к оформлению, типовые адреса не открылись. Проверьте вручную '
            . 'кнопку «Оформить заказ» в корзине.',
    ],
    CHECKOUT_REASON_NO_FORM => [
        'step'     => CHECKOUT_STEP_CHECKOUT,
        'severity' => 'critical',
        'title'    => 'Сценарий заказа: форма оформления заказа не открывается',
        'details'  => 'Страница оформления открылась, но формы с полями покупателя (имя, телефон, email) на ней нет. '
            . 'Покупатель не может оставить заказ.',
    ],
];

/** Причина пропуска → сообщение шага (`%s` — подробности, если есть) */
const CHECKOUT_SKIP_MESSAGES = [
    CHECKOUT_REASON_NO_PRODUCT         => 'Пропущено: страница товара не найдена — сценарий заказа не от чего начинать.',
    CHECKOUT_REASON_BOT_PROTECTION     => 'Пропущено: страница товара закрыта защитой от ботов%s.',
    CHECKOUT_REASON_PRODUCT_NOT_OPENED => 'Пропущено: страница товара не открылась%s.',
    CHECKOUT_REASON_OUT_OF_STOCK       => 'Пропущено: товара нет в наличии — добавить в корзину нельзя, это не поломка.',
    CHECKOUT_REASON_VARIANTS           => 'Пропущено: у товара обязательный выбор вариантов (размер, цвет) — сценарий их не выбирает.',
    CHECKOUT_REASON_ERROR              => 'Пропущено: сценарий прерван%s.',
];

const CHECKOUT_DETAIL_SEPARATOR = ': ';
const CHECKOUT_POSTS_MESSAGE    = 'изменяющих запросов на оформлении: ушло %d, заблокировано %d';
const CHECKOUT_REACHED_MESSAGE  = 'Дошёл до формы оформления заказа (заказ не отправлялся); %s.';
const CHECKOUT_FAILED_MESSAGE   = 'Остановился на шаге «%s»: %s; %s.';
const CHECKOUT_SENT_WARNING     = ' Внимание: на оформлении ушёл изменяющий запрос — проверьте лог перехвата.';

const CHECKOUT_SUMMARY_MAX_CHARS     = FACT_SECTION_MAX_CHARS; // сводка целиком уходит в пакет фактов ИИ
const CHECKOUT_SUMMARY_URL_MAX_CHARS = 200;

/**
 * Результат шага по сценарию, в котором шагам уже проставлены `page_id` / `screenshot_id` (сохранение — воркер).
 *
 * @param array<string, mixed> $scenario результат `runCheckoutScenario()`
 * @param string               $homeUrl  главная — для fingerprint
 * @return array{status: string, message: string, findings: list<array>, summary: array<string, mixed>}
 */
function buildCheckoutResult(array $scenario, string $homeUrl): array
{
    $outcome = (string) ($scenario['outcome'] ?? CHECKOUT_OUTCOME_SKIPPED);
    $reason = (string) ($scenario['reason'] ?? '');
    $detail = isset($scenario['detail']) && $scenario['detail'] !== '' ? (string) $scenario['detail'] : null;
    $requests = normalizeCheckoutRequestStats($scenario['requests'] ?? null);
    $summary = buildCheckoutSummary($scenario);

    $isFailure = $outcome === CHECKOUT_OUTCOME_FAILED && isset(CHECKOUT_FAILURES[$reason]);
    if ($outcome !== CHECKOUT_OUTCOME_REACHED && !$isFailure) {
        return [
            'status'   => CHECKOUT_STATUS_SKIPPED,
            'message'  => buildCheckoutSkipMessage($reason, $detail),
            'findings' => [],
            'summary'  => $summary,
        ];
    }

    $posts = sprintf(CHECKOUT_POSTS_MESSAGE, $requests['checkout_sent'], $requests['blocked']);
    $warning = $requests['checkout_sent'] > 0 ? CHECKOUT_SENT_WARNING : '';
    if ($outcome === CHECKOUT_OUTCOME_REACHED) {
        return [
            'status'   => CHECKOUT_STATUS_DONE,
            'message'  => sprintf(CHECKOUT_REACHED_MESSAGE, $posts) . $warning,
            'findings' => [],
            'summary'  => $summary,
        ];
    }

    $failure = CHECKOUT_FAILURES[$reason];
    $finding = makeCheckoutFinding($reason, $scenario['steps'] ?? [], $homeUrl, $detail, $scenario['signal'] ?? null);

    return [
        'status'   => CHECKOUT_STATUS_DONE,
        'message'  => sprintf(
            CHECKOUT_FAILED_MESSAGE,
            CHECKOUT_STEP_LABELS[$failure['step']],
            describeCheckoutReason($reason),
            $posts
        ) . $warning,
        'findings' => [$finding],
        'summary'  => $summary,
    ];
}

/**
 * Находка поломки: страница и снимок — упавшего шага или ближайшего предыдущего, у которого они есть.
 *
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>
 */
function makeCheckoutFinding(string $reason, array $steps, string $homeUrl, ?string $detail, mixed $signal): array
{
    $failure = CHECKOUT_FAILURES[$reason];
    $evidenceStep = findCheckoutEvidenceStep($steps, $failure['step']);
    $failedStep = findCheckoutStep($steps, $failure['step']);
    $url = $failedStep['url'] ?? $evidenceStep['url'] ?? null;

    $finding = makeFinding(
        'checkout',
        $failure['title'],
        is_string($url) ? $url : null,
        $failure['step'],
        $failure['details'] . ($detail !== null ? "\nПодробности: " . $detail : ''),
        [
            'step'           => $failure['step'],
            'reason'         => $reason,
            'url'            => is_string($url) ? $url : null,
            'screenshot_id'  => $evidenceStep['screenshot_id'] ?? null,
            'signal'         => is_string($signal) ? $signal : null,
            'console_errors' => (int) ($failedStep['console_errors'] ?? 0),
        ],
        isset($evidenceStep['page_id']) ? (int) $evidenceStep['page_id'] : null,
        $failure['severity']
    );
    // Адрес выбранного товара от запуска к запуску меняется — сравнение диагностик по главной и шагу
    $finding['fingerprint'] = buildFingerprint('checkout', $homeUrl, $failure['step']);

    return $finding;
}

/**
 * Последний шаг сценария с этим кодом.
 *
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>|null
 */
function findCheckoutStep(array $steps, string $code): ?array
{
    $found = null;
    foreach ($steps as $step) {
        if (is_array($step) && ($step['code'] ?? null) === $code) {
            $found = $step;
        }
    }

    return $found;
}

/**
 * Шаг со снимком для находки: сам упавший шаг, иначе ближайший предыдущий со снимком (кнопка не найдена — снимок
 * страницы товара).
 *
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>|null
 */
function findCheckoutEvidenceStep(array $steps, string $code): ?array
{
    $order = array_flip(array_keys(CHECKOUT_STEP_LABELS));
    $limit = $order[$code] ?? PHP_INT_MAX;
    $best = null;
    foreach ($steps as $step) {
        if (!is_array($step) || ($order[$step['code'] ?? ''] ?? PHP_INT_MAX) > $limit) {
            continue;
        }
        if (($step['screenshot_id'] ?? null) !== null || ($step['page_id'] ?? null) !== null) {
            $best = $step;
        }
    }

    return $best;
}

function describeCheckoutReason(string $reason): string
{
    return match ($reason) {
        CHECKOUT_REASON_BUTTON_NOT_FOUND   => 'кнопка «В корзину» не найдена',
        CHECKOUT_REASON_BUTTON_DISABLED    => 'кнопка «В корзину» неактивна',
        CHECKOUT_REASON_NOT_ADDED          => 'товар не добавился в корзину',
        CHECKOUT_REASON_CART_NOT_FOUND     => 'корзина не открылась',
        CHECKOUT_REASON_CART_EMPTY         => 'корзина пуста',
        CHECKOUT_REASON_CHECKOUT_NOT_FOUND => 'оформление не открылось',
        CHECKOUT_REASON_NO_FORM            => 'нет формы оформления',
        default                            => $reason,
    };
}

function buildCheckoutSkipMessage(string $reason, ?string $detail): string
{
    $template = CHECKOUT_SKIP_MESSAGES[$reason] ?? CHECKOUT_SKIP_MESSAGES[CHECKOUT_REASON_ERROR];
    if (!str_contains($template, '%s')) {
        return $template;
    }

    return sprintf($template, $detail !== null ? CHECKOUT_DETAIL_SEPARATOR . $detail : '');
}

/**
 * @return array{checkout_sent: int, blocked: int}
 */
function normalizeCheckoutRequestStats(mixed $requests): array
{
    $requests = is_array($requests) ? $requests : [];

    return [
        'checkout_sent' => (int) ($requests['checkout_sent'] ?? 0),
        'blocked'       => (int) ($requests['blocked'] ?? 0),
    ];
}

/**
 * `summary_json.checkout`: шаги без двоичных данных, итог, счётчики перехвата, id страниц сценария. Не длиннее
 * CHECKOUT_SUMMARY_MAX_CHARS: адреса обрезаются, лишние образцы ошибок консоли убираются с конца.
 *
 * @param array<string, mixed> $scenario
 * @return array<string, mixed>
 */
function buildCheckoutSummary(array $scenario): array
{
    $steps = [];
    $pageIds = [];
    foreach ($scenario['steps'] ?? [] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $pageId = isset($step['page_id']) ? (int) $step['page_id'] : null;
        if ($pageId !== null) {
            $pageIds[] = $pageId;
        }
        $steps[] = [
            'code'           => (string) ($step['code'] ?? ''),
            'status'         => (string) ($step['status'] ?? CHECKOUT_STATUS_DONE),
            'url'            => isset($step['url']) ? mb_substr((string) $step['url'], 0, CHECKOUT_SUMMARY_URL_MAX_CHARS) : null,
            'page_id'        => $pageId,
            'screenshot_id'  => isset($step['screenshot_id']) ? (int) $step['screenshot_id'] : null,
            'console_errors' => (int) ($step['console_errors'] ?? 0),
            'console_sample' => array_values(array_map('strval', $step['console'] ?? [])),
            'note'           => isset($step['note']) ? (string) $step['note'] : null,
        ];
    }

    $summary = [
        'outcome'  => (string) ($scenario['outcome'] ?? CHECKOUT_OUTCOME_SKIPPED),
        'reason'   => isset($scenario['reason']) ? (string) $scenario['reason'] : null,
        'reached'  => findLastDoneCheckoutStep($steps),
        'signal'   => isset($scenario['signal']) ? (string) $scenario['signal'] : null,
        'steps'    => $steps,
        'posts'    => normalizeCheckoutRequestStats($scenario['requests'] ?? null),
        'page_ids' => array_values(array_unique($pageIds)),
    ];

    return fitCheckoutSummary($summary);
}

/**
 * @param list<array<string, mixed>> $steps
 */
function findLastDoneCheckoutStep(array $steps): ?string
{
    $reached = null;
    foreach ($steps as $step) {
        if ($step['status'] === CHECKOUT_STATUS_DONE) {
            $reached = $step['code'];
        }
    }

    return $reached;
}

/**
 * Убирает образцы ошибок консоли, затем необязательные заметки и сокращает адреса, пока сводка не поместится.
 * Шаги, их id, счётчики ошибок и статистика запросов сохраняются даже при максимальном числе попыток.
 *
 * @param array<string, mixed> $summary
 * @return array<string, mixed>
 */
function fitCheckoutSummary(array $summary): array
{
    while (getFactJsonLength($summary) > CHECKOUT_SUMMARY_MAX_CHARS) {
        $longest = null;
        foreach ($summary['steps'] as $index => $step) {
            if ($step['console_sample'] !== []
                && ($longest === null || count($step['console_sample']) >= count($summary['steps'][$longest]['console_sample']))) {
                $longest = $index;
            }
        }
        if ($longest === null) {
            break;
        }
        array_pop($summary['steps'][$longest]['console_sample']);
    }

    if (getFactJsonLength($summary) > CHECKOUT_SUMMARY_MAX_CHARS) {
        foreach ($summary['steps'] as &$step) {
            unset($step['note']);
        }
        unset($step);
    }

    while (getFactJsonLength($summary) > CHECKOUT_SUMMARY_MAX_CHARS) {
        $overflow = getFactJsonLength($summary) - CHECKOUT_SUMMARY_MAX_CHARS;
        $longest = null;
        $longestLength = 0;
        foreach ($summary['steps'] as $index => $step) {
            $urlLength = is_string($step['url'] ?? null) ? mb_strlen($step['url']) : 0;
            if ($urlLength > $longestLength) {
                $longest = $index;
                $longestLength = $urlLength;
            }
        }
        if ($longest === null) {
            break;
        }
        $summary['steps'][$longest]['url'] = mb_substr(
            $summary['steps'][$longest]['url'],
            0,
            max(0, $longestLength - $overflow)
        );
    }

    return $summary;
}

/**
 * id страниц сценария из `summary_json.checkout` (у старых диагностик — пусто).
 *
 * @return list<int>
 */
function listCheckoutPageIds(mixed $checkoutSummary): array
{
    $ids = is_array($checkoutSummary) && is_array($checkoutSummary['page_ids'] ?? null) ? $checkoutSummary['page_ids'] : [];

    return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
}

/**
 * Проверенные страницы без страниц сценария заказа: для «Проверенных страниц» отчёта, ИИ-проверки вёрстки и пакета
 * фактов ИИ (сценарий описан в `summary_json.checkout`).
 *
 * @param list<array<string, mixed>> $pages строки `checked_pages`
 * @return list<array<string, mixed>>
 */
function excludeCheckoutPages(array $pages, mixed $checkoutSummary): array
{
    $ids = array_flip(listCheckoutPageIds($checkoutSummary));
    if ($ids === []) {
        return $pages;
    }

    return array_values(array_filter(
        $pages,
        static fn (array $page): bool => !isset($ids[(int) ($page['id'] ?? 0)])
    ));
}
