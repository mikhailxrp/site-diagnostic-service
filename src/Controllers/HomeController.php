<?php

declare(strict_types=1);

namespace App\Controllers;

require_once ROOT_PATH . '/src/Services/UrlValidator.php';

/**
 * Главная = «Новая диагностика»: форма «ссылка + описание проблемы» (отправка — DiagnosticController::store).
 */
final class HomeController
{
    public function index(): void
    {
        $formState = $_SESSION[DiagnosticController::FORM_STATE_KEY] ?? [];
        unset($_SESSION[DiagnosticController::FORM_STATE_KEY]);

        render('pages/home', [
            'title'            => 'Новая диагностика',
            'activeMenu'       => 'new',
            'values'           => readDiagnosticFormValues($formState['values'] ?? []),
            'errors'           => $formState['errors'] ?? [],
            'urlMaxLength'     => SITE_URL_MAX_LENGTH,
            'problemMaxLength' => PROBLEM_TEXT_MAX_LENGTH,
        ]);
    }
}
