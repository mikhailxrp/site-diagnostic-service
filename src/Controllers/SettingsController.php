<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/SettingsModel.php';
require_once ROOT_PATH . '/src/Services/SettingsService.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';

/**
 * Настройки: модели RouterAI и путь к Chrome (в БД), ключи API из .env (только просмотр).
 */
final class SettingsController
{
    /** Ключ сессии: ошибки полей и ввод после неудачной отправки (на один показ) */
    private const FORM_STATE_KEY = 'settings_form';
    private const BROWSER_FORM_STATE_KEY = 'settings_browser_form';

    /** Кнопка «Проверить Chrome»: `name="action" value="check"` */
    private const ACTION_CHECK = 'check';

    public function index(): void
    {
        $formState = $_SESSION[self::FORM_STATE_KEY] ?? [];
        $browserState = $_SESSION[self::BROWSER_FORM_STATE_KEY] ?? [];
        unset($_SESSION[self::FORM_STATE_KEY], $_SESSION[self::BROWSER_FORM_STATE_KEY]);

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
            'title'         => 'Настройки',
            'activeMenu'    => 'settings',
            'modelFields'   => SETTINGS_MODEL_FIELDS,
            'values'        => $values,
            'errors'        => $formState['errors'] ?? [],
            'isEmpty'       => array_intersect_key($saved, SETTINGS_MODEL_FIELDS) === [],
            'envKeys'       => getEnvKeysOverview(),
            'chromePath'    => $browserState['value'] ?? $saved[CHROME_PATH_SETTING] ?? '',
            'chromeError'   => $browserState['error'] ?? null,
            'chromeDefault' => CHROME_DEFAULT_PATH,
        ]);
    }

    /**
     * Сохраняет путь к Chrome; кнопка «Проверить Chrome» после сохранения запускает браузер и показывает версию.
     */
    public function saveBrowser(): void
    {
        requireCsrf();

        ['value' => $value, 'error' => $error] = validateBrowserSettingsInput($_POST);

        if ($error !== null) {
            $_SESSION[self::BROWSER_FORM_STATE_KEY] = ['value' => $value, 'error' => $error];
            setFlash('error', 'Путь к Chrome не сохранён: исправьте поле.');
            redirect('/settings');
        }

        try {
            saveSettings([CHROME_PATH_SETTING => $value]);
        } catch (Throwable $e) {
            logException($e);
            $_SESSION[self::BROWSER_FORM_STATE_KEY] = ['value' => $value, 'error' => null];
            setFlash('error', 'Не удалось сохранить путь к Chrome. Подробности в журнале.');
            redirect('/settings');
        }

        if (input('action') !== self::ACTION_CHECK) {
            setFlash('success', 'Путь к Chrome сохранён.');
            redirect('/settings');
        }

        $check = checkChrome(resolveChromePath($value));
        setFlash($check['ok'] ? 'success' : 'error', $check['message']);
        redirect('/settings');
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
