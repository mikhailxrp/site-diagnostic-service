<?php

declare(strict_types=1);

/**
 * Сигнал остановки воркера: диагностика была прервана, завершена или удалена другим процессом
 * (статус в БД больше не `running`). Наследует `Exception`, а не `RuntimeException`, чтобы
 * локальные `catch (RuntimeException)` в проверках и ИИ-анализе не проглатывали прерывание.
 */
final class DiagnosticNoLongerRunningException extends Exception
{
    public function __construct(public readonly int $diagnosticId, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Диагностика #{$diagnosticId} больше не выполняется.");
    }
}
