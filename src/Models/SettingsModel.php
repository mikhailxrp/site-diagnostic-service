<?php

declare(strict_types=1);

/**
 * Таблица `settings`: несекретные настройки «ключ → значение».
 * Ключи API здесь не хранятся — они в .env.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @return array<string, ?string> ключ → значение
 */
function getAllSettings(): array
{
    $statement = getPdo()->query('SELECT setting_key, setting_value FROM settings');

    return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}

function getSetting(string $key): ?string
{
    $statement = getPdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : $value;
}

/**
 * Сохраняет все значения одной транзакцией: при ошибке не сохраняется ничего.
 *
 * @param array<string, string> $values ключ → значение
 */
function saveSettings(array $values): void
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            $statement->execute(['setting_key' => $key, 'setting_value' => $value]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
