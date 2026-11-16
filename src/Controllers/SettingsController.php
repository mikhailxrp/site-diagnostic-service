<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/SettingsModel.php';
require_once ROOT_PATH . '/src/Services/SettingsService.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';
require_once ROOT_PATH . '/src/Services/AiClient.php';
require_once ROOT_PATH . '/src/Services/AiModels.php';

/**
 * Настройки: модели RouterAI и путь к Chrome (в БД), ключи API из .env (только просмотр).
 */
final class SettingsController
{
    /** Ключ сессии: ошибки полей и ввод после неудачной отправки (на один показ) */
    private const FORM_STATE_KEY = 'settings_form';
    private const BROWSER_FORM_STATE_KEY = 'settings_browser_form';

    /** Кнопки «Проверить Chrome» и «Проверить подключение»: `name="action" value="check"` */
    private const ACTION_CHECK = 'check';

    /** Запас времени PHP сверх тайм-аутов тестовых запросов к RouterAI */
    private const AI_CHECK_EXTRA_SECONDS = 30;

    private const MODELS_UNCHECKED_MESSAGE = 'Не удалось проверить модели по списку RouterAI: %s';

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

        $catalog = loadRouterAiModels()['models'] ?? [];
        $values = [];
        $modelInfo = [];
        foreach (array_keys(SETTINGS_MODEL_FIELDS) as $key) {
            $values[$key] = $formState['values'][$key] ?? resolveModelSetting($saved[$key] ?? null);
            $model = $catalog[$values[$key]] ?? null;
            $modelInfo[$key] = $model !== null ? describeAiModel($model) : null;
        }

        render('pages/settings', [
            'title'         => 'Настройки',
            'activeMenu'    => 'settings',
            'modelFields'   => SETTINGS_MODEL_FIELDS,
            'values'        => $values,
            'modelInfo'     => $modelInfo,
            'defaultModel'  => AI_DEFAULT_MODEL,
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

    /**
     * Сохраняет модели (проверив их по списку RouterAI); «Проверить подключение» после сохранения отправляет
     * тестовый запрос каждой выбранной модели.
     */
    public function save(): void
    {
        requireCsrf();

        ['values' => $values, 'errors' => $errors] = validateSettingsInput($_POST);

        $catalog = ['models' => null, 'error' => null];
        if ($errors === []) {
            $catalog = loadRouterAiModels();
            if ($catalog['models'] !== null) {
                $errors = validateModelsAgainstCatalog($values, $catalog['models']);
            }
        }

        if ($errors !== []) {
            $_SESSION[self::FORM_STATE_KEY] = ['values' => $values, 'errors' => $errors];
            setFlash('error', 'Настройки не сохранены: исправьте отмеченные поля.');
            redirect('/settings');
        }

        try {
            saveSettings($values);
        } catch (Throwable $e) {
            logException($e);
            $_SESSION[self::FORM_STATE_KEY] = ['values' => $values, 'errors' => []];
            setFlash('error', 'Не удалось сохранить настройки. Подробности в журнале.');
            redirect('/settings');
        }

        setFlash('success', 'Настройки сохранены.');
        if ($catalog['models'] === null) {
            setFlash('warning', sprintf(self::MODELS_UNCHECKED_MESSAGE, $catalog['error']));
        }
        if (input('action') === self::ACTION_CHECK) {
            $this->checkConnection(array_values(array_unique($values)));
        }

        redirect('/settings');
    }

    /**
     * Тестовый запрос каждой модели; итог — во flash (успех: модели, время, токены; ошибка: текст RouterAI).
     *
     * @param list<string> $models
     */
    private function checkConnection(array $models): void
    {
        set_time_limit(count($models) * AI_CHECK_TIMEOUT_SECONDS * AI_MAX_ATTEMPTS + self::AI_CHECK_EXTRA_SECONDS);

        $key = env('ROUTERAI_API_KEY', '');
        $keyError = validateAiKey($key);
        if ($keyError !== null) {
            setFlash('error', $keyError);

            return;
        }

        $passed = [];
        $failed = [];
        foreach ($models as $model) {
            $check = checkAiConnection(ROUTERAI_BASE_URL, $model, $key, makeRouterAiTransport(), makeAiSleeper());
            if ($check['ok']) {
                $passed[] = $check['message'];
            } else {
                $failed[] = $check['message'];
                logWarning('Проверка подключения RouterAI не прошла', ['reason' => $check['message']]);
            }
        }

        if ($passed !== []) {
            setFlash('success', 'Подключение работает: ' . implode('; ', $passed) . '.');
        }
        if ($failed !== []) {
            setFlash('error', 'Проверка подключения не прошла. ' . implode(' ', $failed));
        }
    }
}
