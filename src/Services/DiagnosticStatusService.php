<?php

declare(strict_types=1);

/**
 * Состояние диагностики для экрана и JSON-опроса: подписи статусов, классы бейджей Tabler, шаги.
 * Чистые функции без БД — подписи живут здесь, JS получает их в JSON.
 */

const DIAGNOSTIC_ID_PATTERN = '/^[1-9][0-9]{0,9}$/';

const DIAGNOSTIC_STATUS_LABELS = [
    'queued'    => 'В очереди',
    'running'   => 'Выполняется',
    'done'      => 'Готово',
    'failed'    => 'Ошибка',
    'cancelled' => 'Отменена',
];

const DIAGNOSTIC_STATUS_BADGES = [
    'queued'    => 'bg-secondary-lt',
    'running'   => 'bg-blue-lt',
    'done'      => 'bg-green-lt',
    'failed'    => 'bg-red-lt',
    'cancelled' => 'bg-secondary-lt',
];

const STEP_STATUS_LABELS = [
    'pending' => 'Ожидает',
    'running' => 'Выполняется',
    'done'    => 'Готово',
    'skipped' => 'Пропущен',
    'failed'  => 'Ошибка',
];

const STEP_STATUS_BADGES = [
    'pending' => 'bg-secondary-lt',
    'running' => 'bg-blue-lt',
    'done'    => 'bg-green-lt',
    'skipped' => 'bg-yellow-lt',
    'failed'  => 'bg-red-lt',
];

const FINISHED_DIAGNOSTIC_STATUSES = ['done', 'failed', 'cancelled'];

const UNKNOWN_STATUS_BADGE = 'bg-secondary-lt';

/**
 * id из URL: только целое > 0 без знаков и ведущих нулей, не длиннее 10 цифр.
 */
function parseDiagnosticId(string $raw): ?int
{
    return preg_match(DIAGNOSTIC_ID_PATTERN, $raw) === 1 ? (int) $raw : null;
}

/**
 * @param array<string, mixed> $diagnostic строка `diagnostics` + `steps` (как из getDiagnosticWithSteps())
 * @param list<array{code: string, title: string}> $plannedSteps шаги по плану — пока воркер не создал их в БД
 *
 * @return array{
 *     id: int, url: string, status: string, statusLabel: string, badgeClass: string, progress: int,
 *     errorMessage: ?string, isFinished: bool,
 *     steps: list<array{code: string, title: string, status: string, statusLabel: string, badgeClass: string, message: ?string}>
 * }
 */
function buildDiagnosticStatus(array $diagnostic, array $plannedSteps): array
{
    $status = (string) $diagnostic['status'];
    $steps  = $diagnostic['steps'] ?? [];

    if ($steps === []) {
        $steps = array_map(
            static fn (array $step): array => [
                'step_code' => $step['code'],
                'title'     => $step['title'],
                'status'    => 'pending',
                'message'   => null,
            ],
            $plannedSteps
        );
    }

    return [
        'id'           => (int) $diagnostic['id'],
        'url'          => (string) $diagnostic['url'],
        'status'       => $status,
        'statusLabel'  => DIAGNOSTIC_STATUS_LABELS[$status] ?? $status,
        'badgeClass'   => DIAGNOSTIC_STATUS_BADGES[$status] ?? UNKNOWN_STATUS_BADGE,
        'progress'     => (int) $diagnostic['progress'],
        'errorMessage' => isset($diagnostic['error_message']) ? (string) $diagnostic['error_message'] : null,
        'isFinished'   => in_array($status, FINISHED_DIAGNOSTIC_STATUSES, true),
        'steps'        => array_map('buildStepStatus', array_values($steps)),
    ];
}

/**
 * @param array<string, mixed> $step строка `diagnostic_steps`
 *
 * @return array{code: string, title: string, status: string, statusLabel: string, badgeClass: string, message: ?string}
 */
function buildStepStatus(array $step): array
{
    $status = (string) $step['status'];

    return [
        'code'        => (string) $step['step_code'],
        'title'       => (string) $step['title'],
        'status'      => $status,
        'statusLabel' => STEP_STATUS_LABELS[$status] ?? $status,
        'badgeClass'  => STEP_STATUS_BADGES[$status] ?? UNKNOWN_STATUS_BADGE,
        'message'     => isset($step['message']) && $step['message'] !== '' ? (string) $step['message'] : null,
    ];
}
