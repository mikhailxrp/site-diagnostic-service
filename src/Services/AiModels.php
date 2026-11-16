<?php

declare(strict_types=1);

/**
 * Список моделей RouterAI (`GET {base}/models`, без ключа): есть ли модель, понимает ли картинки, умеет ли отвечать
 * по схеме JSON, цена. Список кэшируется на сутки; RouterAI не ответил — берётся старый кэш любой давности.
 * Сеть передаётся параметром (`$fetch`), чтобы тесты не ходили в интернет.
 *
 * Цена хранится целыми копейками за 1 млн токенов (деньги не считаются во float): цена за токен из ответа
 * пересчитывается один раз при разборе.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';

const AI_MODELS_PATH            = '/models';
const AI_MODELS_CACHE_FILE      = ROOT_PATH . '/storage/cache/routerai/models.json';
const AI_MODELS_CACHE_TTL       = 24 * 3600; // список моделей и цены меняются не чаще раза в сутки
const AI_MODELS_RETRY_AFTER     = 5 * 60; // после сбоя сеть не дёргается: недоступный RouterAI — ~10 с на открытие
const AI_MODELS_TIMEOUT_SECONDS = 20;
const AI_MODELS_HTTP_OK         = 200;

const AI_PRICE_TOKENS_UNIT     = 1_000_000; // цена показывается за 1 млн токенов
const AI_KOPECKS_PER_RUBLE     = 100;
const AI_COST_LESS_THAN_KOPECK = '< 0,01 ₽';
const AI_MODALITY_IMAGE        = 'image';
const AI_PARAM_RESPONSE_FORMAT = 'response_format';

const AI_MODELS_ERROR_NO_RESPONSE = 'RouterAI не отдал список моделей.';
const AI_MODELS_ERROR_NOT_LIST    = 'Ответ RouterAI со списком моделей не разобран.';

/**
 * Ответ `GET /models` → модели по id. Неразборчивый ответ → null.
 *
 * @return ?array<string, array{id: string, name: string, images: bool, json_schema: bool,
 *               price_in_kopecks: ?int, price_out_kopecks: ?int}>
 */
function parseAiModelsList(string $body): ?array
{
    $json = json_decode($body, true);
    if (!is_array($json) || !is_array($json['data'] ?? null)) {
        return null;
    }

    $models = [];
    foreach ($json['data'] as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
            continue;
        }
        $modalities = $item['architecture']['input_modalities'] ?? [];
        $parameters = $item['supported_parameters'] ?? [];

        $models[$item['id']] = [
            'id'                => $item['id'],
            'name'              => is_string($item['name'] ?? null) ? $item['name'] : $item['id'],
            'images'            => is_array($modalities) && in_array(AI_MODALITY_IMAGE, $modalities, true),
            'json_schema'       => is_array($parameters) && in_array(AI_PARAM_RESPONSE_FORMAT, $parameters, true),
            'price_in_kopecks'  => convertAiTokenPrice($item['pricing']['prompt'] ?? null),
            'price_out_kopecks' => convertAiTokenPrice($item['pricing']['completion'] ?? null),
        ];
    }

    return $models === [] ? null : $models;
}

/**
 * Цена за токен в рублях (число или строка) → копейки за 1 млн токенов.
 */
function convertAiTokenPrice(mixed $perToken): ?int
{
    if (!is_int($perToken) && !is_float($perToken) && !(is_string($perToken) && is_numeric($perToken))) {
        return null;
    }

    return (int) round((float) $perToken * AI_PRICE_TOKENS_UNIT * AI_KOPECKS_PER_RUBLE);
}

/**
 * Модели из кэша или из RouterAI. Свежий кэш — без сети; сбой сети — старый кэш (`stale`); нет и его — `models` null.
 * После сбоя AI_MODELS_RETRY_AFTER секунд сеть не дёргается (причина сбоя — из кэша).
 *
 * @param callable(): array{status: ?int, body: string, error: ?string} $fetch
 * @return array{models: ?array<string, array>, stale: bool, error: ?string}
 */
function loadAiModels(callable $fetch, string $cacheFile, int $now): array
{
    $cached = readAiModelsCache($cacheFile);
    $models = $cached['models'] ?? null;
    if ($models !== null && ($now - $cached['fetched_at']) < AI_MODELS_CACHE_TTL) {
        return ['models' => $models, 'stale' => false, 'error' => null];
    }
    if ($cached !== null && $cached['failed_at'] !== null && ($now - $cached['failed_at']) < AI_MODELS_RETRY_AFTER) {
        return ['models' => $models, 'stale' => $models !== null, 'error' => $cached['error']];
    }

    $response = $fetch();
    $fetched = null;
    $error = $response['error'] ?? null;
    if ($error === null && ($response['status'] ?? null) === AI_MODELS_HTTP_OK) {
        $fetched = parseAiModelsList((string) $response['body']);
        $error = $fetched === null ? AI_MODELS_ERROR_NOT_LIST : null;
    }

    if ($fetched !== null) {
        writeAiModelsCache($cacheFile, ['fetched_at' => $now, 'models' => $fetched]);

        return ['models' => $fetched, 'stale' => false, 'error' => null];
    }

    $error ??= AI_MODELS_ERROR_NO_RESPONSE;
    writeAiModelsCache($cacheFile, [
        'fetched_at' => $cached['fetched_at'] ?? null,
        'models'     => $models,
        'failed_at'  => $now,
        'error'      => $error,
    ]);

    return ['models' => $models, 'stale' => $models !== null, 'error' => $error];
}

/**
 * @return ?array{fetched_at: ?int, models: ?array<string, array>, failed_at: ?int, error: ?string}
 */
function readAiModelsCache(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }
    $content = file_get_contents($cacheFile);
    $json = is_string($content) ? json_decode($content, true) : null;
    if (!is_array($json)) {
        return null;
    }
    $hasModels = is_int($json['fetched_at'] ?? null) && is_array($json['models'] ?? null);

    return [
        'fetched_at' => $hasModels ? $json['fetched_at'] : null,
        'models'     => $hasModels ? $json['models'] : null,
        'failed_at'  => is_int($json['failed_at'] ?? null) ? $json['failed_at'] : null,
        'error'      => is_string($json['error'] ?? null) ? $json['error'] : AI_MODELS_ERROR_NO_RESPONSE,
    ];
}

/**
 * Запись через временный файл: параллельный запрос не прочитает половину кэша. Сбой записи — только в журнал.
 *
 * @param array{fetched_at: ?int, models: ?array, failed_at?: int, error?: string} $cache
 */
function writeAiModelsCache(string $cacheFile, array $cache): void
{
    $dir = dirname($cacheFile);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        logWarning('Не удалось создать папку кэша моделей RouterAI', ['dir' => $dir]);

        return;
    }

    $temp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($temp, $json) === false || !rename($temp, $cacheFile)) {
        if (is_file($temp)) {
            unlink($temp);
        }
        logWarning('Не удалось записать кэш моделей RouterAI', ['file' => $cacheFile]);
    }
}

/**
 * Модели RouterAI с настоящей сетью и кэшем в `storage/cache/routerai/`.
 *
 * @return array{models: ?array<string, array>, stale: bool, error: ?string}
 */
function loadRouterAiModels(): array
{
    $url = rtrim(ROUTERAI_BASE_URL, '/') . AI_MODELS_PATH;

    return loadAiModels(
        static fn (): array => httpApiRequest($url, null, AI_MODELS_TIMEOUT_SECONDS),
        AI_MODELS_CACHE_FILE,
        time()
    );
}

/**
 * Строка о модели для подсказки: «Qwen: Qwen4 Max · картинки: да · 160 / 481 ₽ за 1 млн токенов».
 */
function describeAiModel(array $model): string
{
    $parts = [(string) $model['name'], 'картинки: ' . ($model['images'] ? 'да' : 'нет')];
    if ($model['price_in_kopecks'] !== null && $model['price_out_kopecks'] !== null) {
        $parts[] = formatAiPrice($model['price_in_kopecks']) . ' / ' . formatAiPrice($model['price_out_kopecks'])
            . ' ₽ за 1 млн токенов';
    }

    return implode(' · ', $parts);
}

/**
 * Копейки → «160», «160,2», «3 844», «0,59».
 */
function formatAiPrice(int $kopecks): string
{
    $rubles = number_format(intdiv($kopecks, AI_KOPECKS_PER_RUBLE), 0, ',', ' ');
    $rest = $kopecks % AI_KOPECKS_PER_RUBLE;

    return $rest === 0 ? $rubles : $rubles . ',' . rtrim(sprintf('%02d', $rest), '0');
}

/**
 * Стоимость ответа в целых копейках по цене модели из каталога (копейки за 1 млн токенов), с округлением до
 * копейки — без float. Токенов нет (ошибка до ответа) — 0: платить не за что. Модели нет в каталоге или у неё нет
 * цены → null.
 *
 * @param array{price_in_kopecks: ?int, price_out_kopecks: ?int}|null $model модель из loadAiModels()['models']
 */
function calculateAiCostKopecks(?int $tokensIn, ?int $tokensOut, ?array $model): ?int
{
    $priceIn = $model['price_in_kopecks'] ?? null;
    $priceOut = $model['price_out_kopecks'] ?? null;
    if ($priceIn === null || $priceOut === null) {
        return null;
    }

    $scaled = ($tokensIn ?? 0) * $priceIn + ($tokensOut ?? 0) * $priceOut;

    return intdiv($scaled + intdiv(AI_PRICE_TOKENS_UNIT, 2), AI_PRICE_TOKENS_UNIT);
}

/**
 * Копейки → «4,20 ₽», «1 230,05 ₽»; меньше копейки (0, но токены были) → «< 0,01 ₽».
 */
function formatAiCost(int $kopecks, bool $hasTokens = true): string
{
    if ($kopecks === 0 && $hasTokens) {
        return AI_COST_LESS_THAN_KOPECK;
    }

    return number_format(intdiv($kopecks, AI_KOPECKS_PER_RUBLE), 0, ',', ' ') . ','
        . sprintf('%02d', $kopecks % AI_KOPECKS_PER_RUBLE) . ' ₽';
}
