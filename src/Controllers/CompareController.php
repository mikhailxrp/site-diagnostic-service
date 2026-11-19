<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/StackModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';
require_once ROOT_PATH . '/src/Services/DiagnosticComparer.php';

/**
 * Сравнение двух завершённых диагностик одного хоста.
 */
final class CompareController
{
    public function index(): void
    {
        $beforeId = parseDiagnosticId(is_scalar($_GET['a'] ?? null) ? (string) $_GET['a'] : '');
        $afterId = parseDiagnosticId(is_scalar($_GET['b'] ?? null) ? (string) $_GET['b'] : '');
        $backUrl = $this->buildBackUrl($beforeId, $afterId);

        if ($beforeId === null || $afterId === null || $beforeId === $afterId) {
            setFlash('error', 'Не удалось сравнить диагностики: проверьте выбранные отчёты.');
            redirect($backUrl);
        }

        try {
            $before = getDiagnosticWithSteps($beforeId);
            $after = getDiagnosticWithSteps($afterId);

            if ($before === null || $after === null) {
                setFlash('error', 'Не удалось сравнить диагностики: один из отчётов не найден.');
                redirect($backUrl);
            }
            if ((string) $before['host'] !== (string) $after['host']) {
                setFlash('error', 'Можно сравнивать только диагностики одного сайта.');
                redirect($backUrl);
            }
            if (
                !in_array((string) $before['status'], REPORT_STATUSES, true)
                || !in_array((string) $after['status'], REPORT_STATUSES, true)
            ) {
                setFlash('error', 'Сравнение доступно только после завершения обеих диагностик.');
                redirect($backUrl);
            }

            $comparison = buildDiagnosticComparison(
                getFindingsByDiagnostic($beforeId),
                getFindingsByDiagnostic($afterId),
                getStackItemsByDiagnostic($beforeId),
                getStackItemsByDiagnostic($afterId)
            );
        } catch (Throwable $e) {
            logException($e, ['before_id' => $beforeId, 'after_id' => $afterId]);
            setFlash('error', 'Не удалось собрать сравнение. Подробности сохранены в журнале.');
            redirect($backUrl);
        }

        if ($comparison['invalidFingerprintCount'] > 0) {
            logWarning('При сравнении пропущены находки с некорректным fingerprint', [
                'before_id' => $beforeId,
                'after_id'  => $afterId,
                'count'     => $comparison['invalidFingerprintCount'],
            ]);
        }

        render('pages/compare', [
            'title'      => 'Сравнение диагностик',
            'activeMenu' => 'history',
            'before'     => $this->buildDiagnosticSummary($before),
            'after'      => $this->buildDiagnosticSummary($after),
            'comparison' => $comparison,
        ]);
    }

    private function buildBackUrl(?int $beforeId, ?int $afterId): string
    {
        $diagnosticId = $afterId ?? $beforeId;

        return $diagnosticId !== null ? '/diagnostics/' . $diagnosticId : '/history';
    }

    /**
     * @param array<string, mixed> $diagnostic
     * @return array{id: int, url: string, status: string, createdAt: string, reportUrl: string, partial: bool}
     */
    private function buildDiagnosticSummary(array $diagnostic): array
    {
        $id = (int) $diagnostic['id'];

        return [
            'id'        => $id,
            'url'       => (string) $diagnostic['url'],
            'status'    => (string) $diagnostic['status'],
            'createdAt' => (string) ($diagnostic['created_at'] ?? ''),
            'reportUrl' => '/diagnostics/' . $id,
            'partial'   => (string) $diagnostic['status'] === 'failed',
        ];
    }
}
