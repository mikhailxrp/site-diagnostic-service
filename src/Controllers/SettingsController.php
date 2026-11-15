<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/SettingsModel.php';
require_once ROOT_PATH . '/src/Services/SettingsService.php';

/**
 * Настройки: модели RouterAI (в БД) и ключи API из .env (только просмотр).
 */
final class SettingsController
{
    /** Ключ сессии: ошибки полей и ввод после неудачной отправки (на один показ) */
    private const FORM_STATE_KEY = 'settings_form';

    public function index(): void
    {
        $formState = $_SESSION[self::FORM_STATE_KEY] ?? [];
        unset($_SESSION[self::FORM_STATE_KEY]);

        try {
            $saved = getAllSettings();
        } catch (Throwable $e) {
            logException($e);
            setFlash('error', 'Не удалось загрузить настройки из базы данных.');
            $saved = [];
        }

        $values = [];
        foreach (array_keys(SETTINGS_MODEL_FIELDS) as $key) {
            $values[$key] = $formState['values'][$key] ?? $saved[$key] ?? '';
        }

        render('pages/settings', [
            'title'       => 'Настройки',
            'activeMenu'  => 'settings',
            'modelFields' => SETTINGS_MODEL_FIELDS,
            'values'      => $values,
            'errors'      => $formState['errors'] ?? [],
            'isEmpty'     => array_intersect_key($saved, SETTINGS_MODEL_FIELDS) === [],
            'envKeys'     => getEnvKeysOverview(),
        ]);
    }

    public function save(): void
    {
        requireCsrf();

        ['values' => $values, 'errors' => $errors] = validateSettingsInput($_POST);

        if ($errors !== []) {
            $_SESSION[self::FORM_STATE_KEY] = ['values' => $values, 'errors' => $errors];
            setFlash('error', 'Настройки не сохранены: исправьте отмеченные поля.');
            redirect('/settings');
        }

        try {
            saveSettings($values);
            setFlash('success', 'Настройки сохранены.');
        } catch (Throwable $e) {
            logException($e);
            $_SESSION[self::FORM_STATE_KEY] = ['values' => $values, 'errors' => []];
            setFlash('error', 'Не удалось сохранить настройки. Подробности в журнале.');
        }

        redirect('/settings');
    }
}
