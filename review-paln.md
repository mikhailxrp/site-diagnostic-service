# План исправления по результатам ревью

Дата ревью: 20.11.2026. Охват: ядро, контроллеры, модели, Views/JS, воркер, Chrome/PDF, HTTP/SSRF, проверки, версии и уязвимости, ИИ-пайплайн.
Файл назван `review-paln.md` по запросу владельца (в имени сохранена опечатка).

## Как пользоваться планом

- Каждый пункт — отдельная задача: сделать, прогнать тесты, записать в `.docs/dev-log.md`. Пункты не смешивать в одну правку.
- Тесты — только на PHP 8.3 OpenServer: `& "C:\OSPanel\modules\PHP-8.3\PHP\php.exe" vendor/bin/phpunit`.
- `git commit` без явной просьбы не делать (правило `AGENTS.md`).
- Примеры кода — ориентир. Перед правкой сверять с актуальным кодом: имена и сигнатуры могли измениться.
- Отдельно (вне пунктов): `TASK.md` для этих правок не перезаписывать — это правки по ревью, а не плановые таски.

## Порядок работ и приоритеты

| Волна | Пункты | Зачем в таком порядке |
|---|---|---|
| 1. Падения воркера (дёшево и массово) | 3, 4, 5, 8, 10, 12 | Мелкие правки, устраняют падения на обычных сайтах (WordPress ≤ 6.0, сайты в windows-1251) |
| 2. Корректность результата | 6, 7 | Без этого отчёт врёт: пропускает критичные CVE |
| 3. Безопасность периметра | 14, 13, 9 | Быстрые защитные меры и память |
| 4. Устойчивость воркера | 11 | Затрагивает модели и несколько шагов, нужны тесты на гонки |
| 5. Безопасность Chrome | 1, 2 | Самое крупное и рискованное, делается после стабилизации остального |
| 6. Сетевая защита HTTP | 15 (бывший 5 по нумерации ревью — см. таблицу ниже) | Включено в пункт 5 |

Соответствие нумерации: пункты плана совпадают с нумерацией итогового отчёта ревью, кроме волны выше — это только порядок выполнения.

| № | Критичность | Кратко |
|---|---|---|
| 1 | Critical | SSRF через headless Chrome |
| 2 | Critical | Риск отправки реального заказа в сценарии оформления |
| 3 | Critical | Переполнение `findings.details` роняет воркер |
| 4 | Critical | Gzip-бомба в sitemap |
| 5 | High | DNS-rebinding: рассинхрон `CURLOPT_URL` и `CURLOPT_RESOLVE` |
| 6 | High | Сравнение версий: пропуск CVE и ложные срабатывания |
| 7 | High | OSV: игнорируется `affected[].versions` |
| 8 | High | Невалидный UTF-8 и длинные URL роняют воркер |
| 9 | High | Тела ответов копятся в памяти |
| 10 | High | Нет `use RuntimeException` в `ExportController` |
| 11 | High | Heartbeat и гонка воркера со «stale»-проверкой |
| 12 | High | `E_WARNING` → `ErrorException` на `parse_str`, `dns_get_record`, `filemtime` |
| 13 | High | PDF без rate limit и блокировка сессии |
| 14 | High | Нет `Require local`; ключи в трассировках |

---

## Пункт 3. Переполнение `findings.details` (Critical)

**Что исправить.**
- `makeFinding()` в `src/Services/FindingFactory.php`: ограничить `details` по байтам.
- `VulnerabilityCheck`: не склеивать неограниченное число блоков.
- `executeStepsWithDb()` в `src/Services/DiagnosticRunner.php`: сохранение находок не должно убивать воркер и оставлять шаг в `running`.

**Почему.** `details` — `TEXT` (65 535 байт). Для WordPress 6.0.0 `details` занимает около 83 КБ, для 4.7 — около 174 КБ. MySQL в строгом режиме кидает `22001 Data too long`. `insertFinding()` вызывается вне `try/catch` шага (`DiagnosticRunner.php` ~973–990, ~1073–1076). Воркер падает, `finishStep()` не вызывается, шаг остаётся `running` навсегда.

**Пример исправления.**

```php
// FindingFactory.php
const FINDING_DETAILS_MAX_BYTES = 60000; // запас под TEXT 65535

'details' => $details !== null
    ? mb_strcut(mb_scrub($details, 'UTF-8'), 0, FINDING_DETAILS_MAX_BYTES, 'UTF-8')
    : null,
```

```php
// VulnerabilityCheck.php: показываем самые серьёзные, остальное — счётчиком
const VULNERABILITY_DETAILS_MAX_BLOCKS = 15;

usort($vulnerabilities, static fn (array $a, array $b): int => ($b['cvss'] ?? 0) <=> ($a['cvss'] ?? 0));
$shown = array_slice($vulnerabilities, 0, VULNERABILITY_DETAILS_MAX_BLOCKS);
$rest  = count($vulnerabilities) - count($shown);
$blocks = array_map(static fn (array $v): string => buildVulnerabilityBlock($v), $shown);
if ($rest > 0) {
    $blocks[] = "…и ещё {$rest} уязвимостей (полный список — в базе Wordfence).";
}
```

```php
// DiagnosticRunner.php, executeStepsWithDb(): onStepFinish
foreach ($findings as $finding) {
    try {
        insertFinding($id, $finding);
    } catch (Throwable $e) {
        logException($e, ['diagnostic_id' => $id, 'step_code' => $step['code']]);
        $status  = STEP_STATUS_FAILED;
        $message = truncateStepMessage('Не удалось сохранить находки: ' . $e->getMessage());
    }
}
finishStep($id, $step['code'], $status, $message); // вызывается всегда
```

**Почему именно так.**
- Ограничение в фабрике защищает все проверки сразу (`PhpErrorCheck`, `SslCheck` и будущие), а не только уязвимости.
- `mb_strcut` режет по байтам и не ломает многобайтный символ. `mb_substr` считал бы символы, а лимит `TEXT` — в байтах, кириллица занимает 2 байта.
- Срез блоков в `VulnerabilityCheck` нужен ради полезности отчёта: 156 записей одним текстом никто не прочитает. Сортировка по CVSS оставляет самое важное.
- `MEDIUMTEXT` не выбран намеренно: это миграция схемы и вывод огромных текстов в UI. Лимит проще и безопаснее.
- Обработка в `onStepFinish` нужна как страховка: даже при другой неожиданной ошибке БД шаг получит финальный статус.

**Проверка.** Юнит-тест: находка с `details` 200 КБ усекается ≤ 60 000 байт и остаётся валидным UTF-8. Тест `executeSteps` с колбэком, бросающим исключение: шаг `failed`, цикл дошёл до конца.

---

## Пункт 4. Gzip-бомба в sitemap (Critical)

**Что исправить.** `parseSitemap()` в `src/Services/Checks/SeoCheck.php` (строки ~413–446).

**Почему.** `HttpClient` ограничивает 5 МБ распакованный `Content-Encoding`, но «сырой» `.gz` приходит как есть. `gzdecode($body)` без `$max_length` разворачивает 5 МБ в гигабайты. Результат — фатальный OOM, который `try/catch` не ловит, воркер умирает.

**Пример исправления.**

```php
const SEO_SITEMAP_MAX_DECODED_BYTES = HTTP_MAX_BODY_BYTES; // 5 МБ после распаковки

try {
    $decoded = gzdecode($body, SEO_SITEMAP_MAX_DECODED_BYTES + 1);
} catch (Throwable) {
    $decoded = false;
}
if ($decoded === false || strlen($decoded) > SEO_SITEMAP_MAX_DECODED_BYTES) {
    return null; // как «битый sitemap»: находка/состояние error, а не падение
}
```

**Почему именно так.**
- Встроенный второй параметр `gzdecode` ограничивает память на уровне zlib, не загружая всё в ОЗУ. Свой потоковый `inflate_add` не нужен.
- Предел совпадает с лимитом HTTP-клиента: поведение для обычного и «сырого» gzip одинаковое, нет новых «магических» чисел.
- Превышение трактуется как нечитаемый sitemap, а не как исключение: шаг SEO продолжает работать.

**Проверка.** Тест: gz-поток, распаковывающийся в > 5 МБ, возвращает `null` без роста памяти (проверять `memory_get_peak_usage`).

---

## Пункт 5. DNS-rebinding: `CURLOPT_URL` против `CURLOPT_RESOLVE` (High)

**Что исправить.** `prepareRequestTarget()` и `sendCurlRequest()` в `src/Services/HttpClient.php`.

**Почему.** Хост нормализуется (точка в конце срезается, IDN → punycode), IP закрепляется в `CURLOPT_RESOLVE` для нормализованного имени. В `CURLOPT_URL` попадает исходный URL: `http://host./x` или сырой UTF-8 IDN. Ключи не совпадают, libcurl делает свой DNS-запрос, и при TTL=0 получает `127.0.0.1`. Защита из комментария «DNS нельзя подменить» не работает.

**Пример исправления.**

```php
// prepareRequestTarget(): вернуть ещё и канонический URL
$authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
$port      = (int) ($parts['port'] ?? HTTP_DEFAULT_PORTS[$scheme]);
$canonical = $scheme . '://' . $authority
    . (isset($parts['port']) ? ':' . $port : '')
    . ($parts['path'] ?? '/')
    . (isset($parts['query']) ? '?' . $parts['query'] : '');

return ['host' => $host, 'port' => $port, 'ip' => $ip, 'url' => $canonical];
```

```php
// httpRequest(): использовать $target['url'] вместо $currentUrl в $request['url']
$request = ['url' => $target['url'], 'method' => $method, 'host' => $target['host'], /* … */];
```

Также в `prepareRequestTarget()` отклонять URL с пробелами и управляющими символами (`preg_match('/[\s\x00-\x1F\x7F]/u', $url)`).

**Почему именно так.**
- Корень проблемы — два разных представления одного хоста. Лечится единственным источником правды: URL пересобирается из тех же частей, по которым делалась проверка.
- Альтернатива «заменить host в `CURLOPT_RESOLVE` на сырой» хуже: пришлось бы зеркалить логику libcurl (регистр, точка, IDN) и ловить её отличия между версиями.
- Фрагмент отбрасывается (он не уходит на сервер), порт указывается явно только если был в исходном URL, чтобы не менять поведение для стандартных портов.

**Проверка.** Тест `prepareRequestTarget`: `http://Example.COM./a?b=1` → `url = http://example.com/a?b=1`, `host = example.com`. Тест с кириллическим хостом → punycode в `url`.

---

## Пункт 6. Сравнение версий (High)

**Что исправить.** `src/Services/VersionService.php`: `normalizeVersion()`, `compareVersions()`.

**Почему.**
- `version_compare('6.4', '6.4.0') === -1`. WordPress сообщает `.0`-релизы как `6.4`, база Wordfence хранит `6.4.0`. Для сайта на 6.4 пропускаются 26 уязвимостей, включая CVE-2024-31211 (CVSS 9.8).
- Для плагинов с границей `< X.Y.0` версия `X.Y` ложно считается уязвимой (567 записей).
- Суффиксы `-beta`/`-rc` отрезаются, поэтому `3.0.0` равна `3.0.0-beta.4` (ложные срабатывания).
- `eol_below = '5.0.0'` ложно срабатывает на `5.0`.

**Пример исправления.**

```php
// Каноническая форма для СРАВНЕНИЯ: хвостовые «.0» срезаются, суффикс сохраняется.
function canonicalVersionForCompare(string $version): string
{
    $version = strtolower(trim($version));
    $version = ltrim($version, 'v');

    return (string) preg_replace('~(?:\.0+)+(?=$|[-+])~', '', $version); // 6.4.0 → 6.4, 6.0.0-rc1 → 6-rc1
}

function compareVersions(string $a, string $b): int
{
    return version_compare(canonicalVersionForCompare($a), canonicalVersionForCompare($b));
}
```

`VERSION_PATTERN` для **обнаружения** версии в HTML оставить как есть. Для сравнения использовать новую функцию, которая не отрезает `-beta/-rc/-pN`. У `version_compare` порядок `dev < alpha < beta < rc < (релиз) < pl/p`, он уже верен для этих суффиксов.

**Почему именно так.**
- Сравнение и распознавание — разные задачи. Менять регулярку обнаружения опасно: она кормит стек, отчёт и кеши. Отдельная функция нормализации для сравнения локализует риск.
- Срез хвостовых нулей делает `6.4 == 6.4.0 == 6.4.0.0`, что соответствует смыслу релизов.
- Использование встроенного `version_compare` вместо своего парсера исключает собственные баги в порядке пре-релизов.

**Проверка.** Таблица тестов: `6.4` vs `6.4.0` → 0; `5.0` vs `5.0.0` → 0; `3.0.0` vs `3.0.0-beta.4` → 1; `1.4.1-rc2` < `1.4.1`; `1.0-p1` > `1.0`. Регресс на реальной базе: сайт WP 6.4 получает CVE-2024-31211 в находках.

---

## Пункт 7. OSV: `affected[].versions` (High)

**Что исправить.** `parseOsvResponse()` в `src/Services/OsvClient.php` (строки ~76–90).

**Почему.** Часть советов OSV перечисляет уязвимые версии списком `affected[].versions` (или рядом с диапазоном `GIT`). Код смотрит только `ranges` типов `ECOSYSTEM`/`SEMVER`, получает `ranges => []`, и `isVersionVulnerable()` всегда возвращает `false`. Подтверждённая уязвимость молча теряется.

**Пример исправления.**

```php
foreach (is_array($affected['versions'] ?? null) ? $affected['versions'] : [] as $exact) {
    $normalized = normalizeVersion((string) $exact);
    if ($normalized !== null) {
        $ranges[] = ['from' => $normalized, 'from_inclusive' => true,
                     'to' => $normalized, 'to_inclusive' => true];
    }
}
```

Формат элемента `ranges` привести к тому, что уже строит `parseOsvEvents()` (сверить ключи в коде).

**Почему именно так.** Точная версия — это вырожденный диапазон `[v, v]`. Новая ветка в сравнении не нужна: используется существующий `isVersionVulnerable()`.

**Проверка.** Тест на ответе OSV с `versions: ["1.7.8.0"]`: версия `1.7.8.0` уязвима, `1.7.8.1` — нет.

---

## Пункт 8. Невалидный UTF-8 и длинные URL (High)

**Что исправить.**
- `src/Models/FindingModel.php` (строка 37) и `src/Models/AiResultModel.php` (`encodeAiResultJson`): добавить `JSON_INVALID_UTF8_SUBSTITUTE`.
- `src/Services/FindingFactory.php`: `mb_scrub` для `title`, `details`, `location`.
- `src/Models/PageModel.php`: усекать `url` до 2048.
- `src/Services/StackDetector.php` (~444, ~751): `mb_scrub` для `name` и `version`.

**Почему.** `StackModel` и `DiagnosticModel` уже используют `JSON_INVALID_UTF8_SUBSTITUTE`, а `FindingModel` — нет. Магазин в windows-1251 (кириллица в `Location`, CSS `url()`, `<loc>`) даёт `JsonException`. Он вылетает вне `try/catch` шага. URL длиннее 2048 даёт `PDOException 22001`. Итог тот же: падение воркера и вечный `running`.

**Пример исправления.**

```php
// FindingModel.php
json_encode(
    $finding['evidence'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
)
```

```php
// FindingFactory.php
'title'    => mb_substr(mb_scrub($title, 'UTF-8'), 0, FINDING_TITLE_MAX_LENGTH),
'location' => $location !== null ? mb_substr(mb_scrub($location, 'UTF-8'), 0, FINDING_LOCATION_MAX_LENGTH) : null,
```

```php
// PageModel.php
const CHECKED_PAGE_URL_MAX_LENGTH = 2048;
'url' => mb_substr(mb_scrub($page['url'], 'UTF-8'), 0, CHECKED_PAGE_URL_MAX_LENGTH),
```

**Почему именно так.**
- Повторяем уже принятый в проекте приём (`StackModel`), не изобретаем нового.
- Очистка на входе (`makeFinding`) защищает сразу и БД, и ИИ-пакет фактов, и отчёт. Флаг JSON — вторая линия защиты для `evidence`.
- Усечение URL лучше, чем отказ: страница всё равно попадёт в отчёт, пусть и с обрезанным адресом.
- Совместно с пунктом 3 (страховка в `executeStepsWithDb`) падение воркера становится невозможным и при будущих ошибках такого класса.

**Проверка.** Тест: evidence с байтом `\xC0` сохраняется без исключения. Тест: URL 3000 символов усекается до 2048.

---

## Пункт 9. Тела ответов в памяти (High)

**Что исправить.**
- `src/Services/Checks/ResourceCheck.php` (~92–113, ~418–437).
- `src/Services/ShopCrawler.php` (~120–158, ~444–466).

**Почему.** Для каждого ресурса с `HEAD >= 400` (например, 405 на CDN) делается полный `GET` до 5 МБ, и `body` хранится в `$entries[..]['checked']` до конца шага, хотя нужен только для CSS и только один раз. `ShopCrawler` держит полные тела всех опрошенных кандидатов без ограничения их числа. ~200 ресурсов по 5 МБ превышают `memory_limit` и дают неперехватываемый фатал.

**Пример исправления.**

```php
// ResourceCheck.php
$checked = checkResource($resource['url'], $isStyle, $request);
$cssBody = $isStyle ? $checked['body'] : '';
unset($checked['body']);                       // в entries тела не храним
$entries[$resource['url']]['checked'] = $checked;
// … дальше разбор $cssBody, после — unset($cssBody)

// checkResource(): если тело не нужно, просим минимум
$maxBytes = $needBody ? null : RESOURCE_EXISTENCE_PROBE_BYTES; // например 1
$response = $request($url, $method, $maxBytes);
```

Для обхода: после классификации кандидата сохранять в `$crawl['responses'][$key]` только метаданные (`status`, `final_url`, `type`), а тело — лишь у выбранных страниц (главная, категория, товар, корзина, оформление).

Жёсткий лимит числа ресурсов за диагностику (именованная константа, значение согласовать с владельцем: в решениях фазы «лимитов нет, добавим когда упрёмся» — это тот самый случай).

**Почему именно так.**
- Корень проблемы — хранение лишнего. Уменьшение `memory_limit` или `gc` ничего не лечит.
- Запрос минимума (`Range`) для проверки существования — механизм `maxBytes` уже есть в `httpRequest()`.
- Лимит числа ресурсов вводится по явному решению владельца, а не молча, чтобы не нарушить принятое «лимитов нет».
- Дополнительно исправить `<= RESOURCE_CSS_MAX_DEPTH` (с глубиной 1 разбирается лишний уровень импортов) — подтвердить по комментарию в коде.

**Проверка.** Тест на синтетическом транспорте: 300 ресурсов по 5 МБ — пик памяти остаётся ниже порога.

---

## Пункт 10. `use RuntimeException` в `ExportController` (High)

**Что исправить.** `src/Controllers/ExportController.php`, строки 5–7.

**Почему.** В `namespace App\Controllers` импортирован только `Throwable`. `new RuntimeException(...)` в `sendPdf()` ищет `App\Controllers\RuntimeException`, которого нет. Вместо понятной ошибки в логе появляется `Error: Class not found`.

**Пример исправления.**

```php
use RuntimeException;
use Throwable;
```

**Почему именно так.** Это однострочная правка без изменения поведения; корректнее, чем писать `\RuntimeException` в одном месте и оставлять неоднородность. Дополнительно просканировать остальные контроллеры на неимпортированные глобальные классы: `grep -n "new [A-Z]\w*Exception" src/Controllers`.

**Проверка.** Юнит-тест `sendPdf` на несуществующем пути: бросается `RuntimeException` с текстом из кода.

---

## Пункт 12. `E_WARNING` → `ErrorException` (High)

**Что исправить.**
- `StackDetector::extractQueryVersion()` (~578–589): убрать `parse_str`.
- `UrlValidator::resolveHostIps()` (~186–199): защитить `dns_get_record`.
- `BrowserService::removeStaleBrowserProfiles()` (~191–203): защитить `filemtime`.
- `prepareRequestTarget()`: перехватывать исключения резолвера.

**Почему.** `bin/worker.php` превращает любое предупреждение PHP в `ErrorException`. Одно `<script src="…?a=&a=&…">` на 30 КБ превышает `max_input_vars` и роняет шаг `stack`. Следом пропускаются `outdated` и `vulnerabilities`. SERVFAIL на AAAA-запросе роняет шаг целиком. Гонка двух процессов в `glob/filemtime` роняет браузерный шаг.

**Пример исправления.**

```php
// StackDetector.php
function extractQueryVersion(string $url): ?string
{
    $query = (string) parse_url($url, PHP_URL_QUERY);

    return preg_match('~(?:^|&)(?:ver|v|version)=([^&]+)~i', $query, $m) === 1 ? rawurldecode($m[1]) : null;
}
```

```php
// UrlValidator.php
function resolveHostIps(string $host): array
{
    $ips = gethostbynamel($host) ?: [];
    try {
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    } catch (Throwable) {
        // временный сбой AAAA не отменяет проверку по A-записям
    }

    return array_values(array_unique($ips));
}
```

```php
// BrowserService.php
try {
    $modified = filemtime($dir);
} catch (Throwable) {
    continue; // папку удалил другой процесс
}
```

**Почему именно так.**
- Замена `parse_str` регулярным поиском убирает саму причину (лимит входных переменных и большие массивы), а не ловит последствие.
- Блокировать конвертацию warning→exception в воркере нельзя: она нужна проекту, чтобы ничего не терялось молча. Поэтому точечно ловим только функции с заведомо «шумными» предупреждениями (как уже сделано в `SslCheck`).
- Проверка `checkdnsrr` перед `dns_get_record` не спасает от гонки и сбоев DNS, поэтому её убираем вместе с `try/catch`.

**Проверка.** Тест: URL с 20 000 параметрами → `null`/версия без исключения. Тест резолвера с бросающей заглушкой не роняет `httpRequest()`.

---

## Пункт 14. `Require local` и утечка ключей в трассировках (High)

**Что исправить.**
- `public/.htaccess`: добавить `Require local`.
- `src/Services/AiClient.php`: `#[\SensitiveParameter]` у `$key` в `sendAiRequest()` и `checkAiConnection()`.
- `src/Services/Checks/SpeedCheck.php`: то же для `runSpeedCheck()`.
- `src/Core/Logger.php`: скрубить известные ключи из message и trace.

**Почему.** Архитектура без входа опирается на «только с этого ПК», но в `.htaccess` защиты нет. Сейчас спасает только то, что OpenServer слушает `127.127.126.54`. Если сменить IP на LAN, приложение (включая запуск `.exe` через `POST /settings/browser`) откроется всей сети. `zend.exception_string_param_max_len = 15`, поэтому в трассировках остаются первые 15 символов ключа там, где нет `#[\SensitiveParameter]`.

**Пример исправления.**

```apache
# public/.htaccess
<IfModule mod_authz_core.c>
    Require local
</IfModule>

Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

```php
function sendAiRequest(
    string $baseUrl,
    array $payload,
    #[\SensitiveParameter] string $key,
    callable $api,
    callable $sleep,
    int $timeoutSeconds = AI_TIMEOUT_SECONDS
): array {
```

```php
// Logger.php: страховка для значений ключей из .env
function redactKnownSecrets(string $text): string
{
    foreach (['ROUTERAI_API_KEY', 'PAGESPEED_API_KEY', 'WORDFENCE_API_KEY'] as $name) {
        $secret = env($name, '');
        if (strlen($secret) >= 8) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }

    return $text;
}
```

**Почему именно так.**
- `Require local` — защита, не зависящая от настроек OpenServer, и совпадает с документированным решением владельца. Обёртка в `IfModule` не ломает старт Apache на нестандартной сборке.
- Обязательно проверить после правки (ручной шаг): сайт открывается по `https://service-diagnostic-site.local` с этого ПК. «Local» сравнивает адрес клиента, а не имя хоста.
- `#[\SensitiveParameter]` — уже принятый в `Services/Ai/*` приём; лучше, чем менять `zend.exception_string_param_max_len` глобально для всего OpenServer.
- Скрабер логов закрывает остаточные места (текст исключения), куда атрибут не достаёт. Ключи короче 8 символов не трогаем, чтобы не портить логи ложными заменами.

**Проверка.** Тест: исключение внутри `sendAiRequest` с тестовым ключом — в `logException` его нет. Ручная: запрос с другого IP получает 403 (если есть возможность).

---

## Пункт 13. PDF без rate limit и блокировка сессии (High)

**Что исправить.**
- `ExportController::download()`: лимит частоты.
- `public/index.php` / тяжёлые контроллеры: освобождение блокировки сессии.

**Почему.** Каждый `GET /diagnostics/{id}/pdf` запускает отдельный Chrome (до 120 с). Лимита нет, в отличие от запуска, повтора и перезапуска ИИ. Сессия блокируется файлом до конца запроса: пока идёт PDF (до 120 с) или проверка RouterAI (до 540 с), остальные вкладки и опрос `/status` висят.

**Пример исправления.**

```php
// ExportController.php
private const RATE_LIMIT_ACTION         = 'pdf_export';
private const RATE_LIMIT_MAX_ATTEMPTS   = 5;
private const RATE_LIMIT_WINDOW_SECONDS = 60;

// после проверки id/версии/статуса, перед запуском Chrome
if (!takeRateLimitAttempt(self::RATE_LIMIT_ACTION, self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_WINDOW_SECONDS)) {
    logWarning('Превышен лимит экспорта PDF', ['diagnostic_id' => $diagnosticId]);
    $this->fail($reportUrl, 'Слишком много экспортов подряд. Подождите минуту.');
}
session_write_close(); // сессия больше не нужна до конца экспорта
```

`fail()` вызывает `setFlash()` → `ensureSessionStarted()`, который заново откроет сессию, пока заголовки не отправлены. То же `session_write_close()` — перед `checkChrome()` и `checkConnection()` в `SettingsController`, после того как все данные сессии прочитаны.

**Почему именно так.**
- Тот же `takeRateLimitAttempt()`, что уже защищает остальные тяжёлые действия: единый атомарный механизм, нет новой логики.
- `GET`, который запускает тяжёлую работу, превращать в POST не стали: ссылки «PDF» — обычные ссылки в отчёте, смена метода меняет UI и PDF-режим; лимит решает проблему без этого.
- `session_write_close()` — стандартное лекарство от блокировок сессии. Глобальный отказ от сессий не нужен: они нужны для CSRF и флешей.

**Проверка.** Шестой экспорт за 60 с получает флеш без запуска Chrome. Ручная: во время PDF соседняя вкладка отвечает.

---

## Пункт 11. Heartbeat и гонка воркера (High)

**Что исправить.**
- `src/Services/DiagnosticRunner.php`: heartbeat по времени для всех сетевых шагов.
- `src/Models/DiagnosticModel.php`: защитные условия `AND status = 'running'`.
- `src/Services/Checks/SeoCheck.php`: лимит `Sitemap:`.
- `src/Services/Checks/OutdatedCheck.php`, `StackDetector.php`: лимит плагинов и онлайн-запросов.
- `bin/worker.php`: обработка фатальных ошибок.

**Почему.** Шаги SEO, outdated, stack и resources редко обновляют heartbeat. 10 зависших запросов дают больше 600 с (`HEARTBEAT_STALE_SECONDS`) без отметки. Опрос `/status` помечает диагностику `failed`, а живой воркер идёт дальше. Он затирает `failed` обратно в `done` либо работает по уже удалённой записи (историю можно удалять, когда статус `failed`). 50 000 директив `Sitemap:` и 5 000 фейковых плагинов создают часы запросов и тысячи файлов кеша.

**Пример исправления.**

```php
// DiagnosticRunner.php: единая обёртка — heartbeat не чаще раза в 30 секунд
const HEARTBEAT_MIN_INTERVAL_SECONDS = 30;

function withHeartbeat(callable $request, int $diagnosticId): callable
{
    $last = 0;

    return static function (...$args) use ($request, $diagnosticId, &$last) {
        $now = time();
        if ($now - $last >= HEARTBEAT_MIN_INTERVAL_SECONDS) {
            touchDiagnosticHeartbeat($diagnosticId);
            $last = $now;
        }

        return $request(...$args);
    };
}

// использование
$result = runSeoCheck($context, withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId));
```

```php
// DiagnosticModel.php: воркер не затирает чужие решения
$statement = $pdo->prepare(
    "UPDATE diagnostics SET status = :status, error_message = :error, finished_at = NOW()
     WHERE id = :id AND status = 'running'"
);
$statement->execute([...]);
if ($statement->rowCount() === 0) {
    throw new DiagnosticNoLongerRunningException($id); // воркер завершается тихо
}
```

```php
// SeoCheck.php
const SEO_MAX_SITEMAP_CANDIDATES = 5;
$seen[$value] ??= ($sitemaps[] = $value);            // O(1) вместо in_array
$candidates = array_slice($candidates, 0, SEO_MAX_SITEMAP_CANDIDATES);
```

```php
// OutdatedCheck.php / StackDetector.php
const WP_PLUGIN_LOOKUP_MAX_PER_DIAGNOSTIC = 30;
```

```php
// bin/worker.php
register_shutdown_function(static function () use ($diagnosticId): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        failRunningSteps($diagnosticId, 'Воркер аварийно завершился: недостаточно памяти или фатальная ошибка.');
        finishDiagnostic($diagnosticId, 'failed', 'Воркер аварийно завершился.');
    }
});
```

**Почему именно так.**
- Обёртка вместо правки каждой проверки: heartbeat добавляется в одном месте, покрывает все текущие и будущие шаги, не засоряет чистые функции проверок (они остаются без БД).
- Ограничение «не чаще 30 с» снижает нагрузку на БД и достаточно мало по сравнению с 600 с.
- Условие `status = 'running'` — оптимистичная блокировка в SQL: проще и надёжнее, чем блокировки на уровне PHP. Если запись уже `failed`/удалена, воркер прекращает работу и не тратит платные запросы ИИ.
- Конкретные значения лимитов (5 sitemap, 30 плагинов) — предложение. Принять решение с владельцем, так как ранее принято «лимитов обхода нет».
- Shutdown-обработчик — единственный способ сообщить о фатальной ошибке: `catch (Throwable)` её не ловит.

**Проверка.** Юнит-тесты на модель: обновление не меняет запись в статусе `failed`. Тест `withHeartbeat` (подмена `time()` через параметр). Ручная: воркер с зависшим ресурсом остаётся `running` и не помечается stale.

---

## Пункт 2. Риск отправки реального заказа (Critical)

**Что исправить.**
- `src/Services/CheckoutScenario.php`: `decideCheckoutRequest()`, `attachCheckoutListeners()`, `runCheckoutScenario()`, `CHECKOUT_PROBE_SCRIPT`.
- `data/checkout-selectors.php`: список `add_to_cart_texts` и `requests`.
- `src/Services/BrowserService.php`: параметры запуска Chrome для сценария.

**Почему.**
1. На стадии добавления в корзину изменяющие запросы по умолчанию **разрешены**. Блокируются только URL, похожие на заказ. Заказ через общий RPC-адрес (`admin-ajax.php`, `/bitrix/services/main/ajax.php`, `/graphql`, `/api/buy`) проходит: действие — в теле `POST`, тело не анализируется.
2. `GET`-запросы никогда не блокируются, даже на странице оформления. «Быстрый заказ» по ссылке или `fetch` по GET уходит на живой магазин.
3. Кнопки «купить» / `buy now` разрешены, а скрипт анализирует атрибуты только самой кнопки, а не `<form action>` родителя и `data-*`.
4. `Fetch.enable` включён только на вкладке. Всплывающие окна (`window.open`, `target=_blank`; в `chrome-php` включён `--disable-popup-blocking`), Service Worker и iframe других доменов выходят из-под перехвата.
5. `Fetch.disable` вызывается **до** закрытия вкладки: `sendBeacon` и `keepalive`-запросы в `pagehide` уходят без перехвата.

**Пример исправления.**

```php
// decideCheckoutRequest(): на стадии «в корзину» — блокировать, если это не похоже на добавление в корзину
function decideCheckoutRequest(
    string $method, string $url, string $resourceType, string $stage, array $patterns, ?string $postData = null
): array {
    $subject = crawlUrlSubject($url);
    if ($resourceType === CHECKOUT_DOCUMENT_TYPE && matchesAnyPattern($subject, $patterns['checkout_document'])) {
        $stage = raiseCheckoutStage($stage, CHECKOUT_STEP_CHECKOUT);
    }

    $isOrderUrl   = matchesAnyPattern($subject, $patterns['order']) && !matchesAnyPattern($subject, $patterns['add_to_cart']);
    $isMutating   = in_array(strtoupper($method), CHECKOUT_MUTATING_METHODS, true);

    // GET на «заказный» адрес блокируется всегда (кроме загрузки самих страниц корзины/оформления)
    if (!$isMutating) {
        return ['block' => $isOrderUrl && $resourceType !== CHECKOUT_DOCUMENT_TYPE, 'stage' => $stage];
    }
    if ($stage === CHECKOUT_STEP_CHECKOUT || $isOrderUrl) {
        return ['block' => true, 'stage' => $stage];
    }

    // default-DENY: пропускаем только то, что явно похоже на «добавить в корзину»
    $looksLikeAdd = matchesAnyPattern($subject, $patterns['add_to_cart'])
        || ($postData !== null && preg_match($patterns['add_to_cart_body'], $postData) === 1);
    $looksLikeOrder = $postData !== null && preg_match($patterns['order_body'], $postData) === 1;

    return ['block' => !$looksLikeAdd || $looksLikeOrder, 'stage' => $stage];
}
```

В `data/checkout-selectors.php` добавить `add_to_cart_body` (например `~(add[_-]?to[_-]?cart|addtocart|basket/add|cart/add)~i`) и `order_body` (`~(order|checkout|purchase|oneclick|one_click|quick_order|payment)~i`). `postData` брать из `Fetch.requestPaused` (`$request['postData']`).

```php
// родительская форма и data-атрибуты кнопки
const attrNames = ['class','id','name','onclick','href','title','aria-label','data-action',
                   'formaction','value','data-url','data-href','data-target'];
const formAction = el.closest('form')?.getAttribute('action') || '';
attrs: norm(attrNames.map(a => el.getAttribute(a) || '').join(' ') + ' ' + formAction).slice(0, 500),
```

```php
// attachCheckoutListeners(): закрыть обходы
$session->sendMessageSync(new Message('Network.setBypassServiceWorker', ['bypass' => true]), BROWSER_COMMAND_TIMEOUT_MS);
// запрет новых окон — флаг Chrome для сценария заказа
'customFlags' => ['--block-new-web-contents'],
// и перехват во вложенных фреймах/целях:
$session->sendMessageSync(new Message('Target.setAutoAttach', [
    'autoAttach' => true, 'waitForDebuggerOnStart' => true, 'flatten' => true,
]), BROWSER_COMMAND_TIMEOUT_MS);
```

```php
// runCheckoutScenario(): НЕ отключать перехват перед закрытием
} finally {
    if ($page !== null) {
        try { $page->close(); } catch (Throwable) {}
    }
    $connection->setStrict($wasStrict);
}
// браузер закрывает вызывающий код — перехват жив до конца
```

Дополнительно: убрать из `add_to_cart_texts` `buy now` и отдельное слово `купить` у кнопок, внутри формы которых `action` совпадает с `order_body`/`order`. «Купить в 1 клик» исключать по тексту (`в 1 клик`, `быстрый заказ`).

**Почему именно так.**
- Принцип «запретить всё, что не доказано безопасным» (default-deny) надёжнее чёрного списка URL: чёрный список невозможно исчерпывающе составить для тысяч CMS и кастомных RPC.
- Решение по телу `POST` необходимо, потому что WooCommerce, Битрикс и GraphQL используют один адрес для всех действий. Добавление в корзину при этом остаётся работоспособным (по шаблонам тела).
- Блокировка GET — именно «заказных» адресов, не всех GET: иначе нельзя открывать страницы сайта.
- `--block-new-web-contents` и `setBypassServiceWorker` закрывают пути обхода без попытки перехватывать каждый новый CDP-target вручную (хрупко и зависит от версии `chrome-php`).
- Не вызывать `Fetch.disable` до закрытия вкладки: перехватчик должен жить, пока страница может отправлять запросы.
- Риск: default-deny может ломать «добавление в корзину» на нестандартных сайтах — тогда сценарий честно завершится как «не удалось добавить», что безопаснее, чем случайный заказ. Результат сценария нужно проверять на 3–4 реальных магазинах разных CMS без заказа (только по журналу перехвата), по DoD фазы 5.

**Проверка.** Юнит-тесты `decideCheckoutRequest` (таблица: GET order-URL, POST admin-ajax с `action=woocommerce_add_to_cart` → пропустить, POST `/ajax/oneclick.php` → блок, POST на стадии checkout → блок). Ручной E2E по журналу перехвата (`checkout_sent = 0`). Отдельное разрешение владельца на запуск на живых магазинах (внешнее действие).

---

## Пункт 1. SSRF через headless Chrome (Critical)

**Что исправить.**
- `src/Services/BrowserService.php`: `buildBrowserOptions()`, `openBrowserPage()`, `navigateBrowserPage()`, `printBrowserHtmlToPdf()`.
- `src/Services/CheckoutScenario.php`: общая функция проверки адреса.
- Новый файл `src/Services/BrowserRequestGuard.php` (чистая функция проверки запроса).

**Почему.** Защита (`UrlValidator` + `CURLOPT_RESOLVE`) есть только в PHP-клиенте. Chrome сам ходит по сети без ограничений, да ещё с `ignoreCertificateErrors`. Враждебный сайт может по редиректу или JS отправить Chrome на `https://service-diagnostic-site.local/settings`, `127.0.0.1:порт` или адрес в LAN. Chrome запущен локально, `Require local` его не останавливает. Полностраничный снимок такой страницы сохраняется в отчёт и уходит во внешний ИИ. Через GET-эндпоинт PDF можно параллельно запускать десятки Chrome.

**Пример исправления.**

```php
// BrowserRequestGuard.php — чистая функция, тестируется без Chrome
function isBrowserRequestAllowed(string $url, callable $resolver): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (in_array($scheme, ['data', 'blob', 'about'], true)) {
        return true; // локальные схемы страницы, сети не касаются
    }
    if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
        return false; // file:, ftp:, chrome: и прочее
    }

    $host = normalizeHostName((string) parse_url($url, PHP_URL_HOST));

    return $host !== null && validateSiteHost($host, $resolver) === null;
}
```

```php
// openBrowserPage(): перехват ВСЕХ запросов страницы
$session->on('method:Fetch.requestPaused', static function (array $params) use ($session): void {
    $allowed = isBrowserRequestAllowed((string) ($params['request']['url'] ?? ''), 'resolveHostIps');
    $session->sendMessage($allowed
        ? new Message('Fetch.continueRequest', ['requestId' => $params['requestId']])
        : new Message('Fetch.failRequest', ['requestId' => $params['requestId'], 'errorReason' => 'BlockedByClient']));
});
$session->sendMessageSync(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]), BROWSER_COMMAND_TIMEOUT_MS);
```

```php
// printBrowserHtmlToPdf(): PDF-страница — только file://, без JS и без сети
$session = launchBrowser($chromePath, $ownerId, BROWSER_PDF_STARTUP_TIMEOUT_SECONDS, ['disableJavascript' => true]);
// и тот же Fetch-перехват, но разрешающий только file:// и data:
```

Для DNS-rebinding внутри Chrome (резолв и соединение — разные запросы) следующий этап — **локальный фильтрующий прокси** на PHP (`--proxy-server=127.0.0.1:<порт>`), который резолвит и закрепляет публичный IP, как делает `HttpClient`. Этот этап вынести в отдельную задачу; первый этап (Fetch-перехват) закрывает прямые редиректы на локальные адреса, что и есть основной вектор.

**Почему именно так.**
- Единая политика адресов: те же `normalizeHostName()` и `validateSiteHost()`, что для PHP-клиента. Не заводим второй набор правил, которые разъедутся.
- Перехват на уровне CDP (`Fetch`) виден для всех запросов вкладки, включая редиректы и подресурсы. Параметры запуска Chrome (`--host-resolver-rules`) не подходят: нельзя перечислить исключения для неизвестных заранее доменов проверяемого магазина.
- Прокси — единственный полный ответ на rebinding, но это инфраструктура (процесс, порт, жизненный цикл). Делать её вместе с остальным объёмом нельзя: повысит риск. Поэтому два этапа.
- `ignoreCertificateErrors` оставляем: он нужен, чтобы открыть сайты с проблемами SSL (их ловит `SslCheck`). После перехвата он перестаёт быть вектором для локальных адресов.
- Для PDF отключаем JS и сеть: страница строится только из локального HTML, любой выход наружу — признак ошибки экранирования.

**Проверка.** Юнит-тесты `isBrowserRequestAllowed`: `127.0.0.1`, `localhost`, `service-diagnostic-site.local`, `::ffff:127.0.0.1`, `192.168.0.1`, `file:///C:/…` — запрещены; публичный домен — разрешён. Ручной E2E: тестовая страница с `location.href = 'http://127.0.0.1/'` — в журнале запрос заблокирован, скриншот пустой/ошибка, ИИ не получает локальную страницу. Проверить 360/768/1280 px не требуется (UI не меняется).

---

## Общая проверка после всех пунктов

1. Полный `phpunit` на PHP 8.3 OpenServer — зелёный.
2. `storage/logs/app.log` — без новых PHP errors и warnings, кроме ожидаемых записей лимитов.
3. Реальные E2E (с отдельного согласия владельца):
   - диагностика WordPress ≤ 6.0 доходит до `done`, находки уязвимостей сохранены;
   - сайт в windows-1251 не роняет воркер;
   - WordPress 6.4 получает CVE-2024-31211;
   - сценарий оформления на 2–3 магазинах: `checkout_sent = 0`, заказ не создан;
   - редирект Chrome на `127.0.0.1` заблокирован.
4. Запись в `.docs/dev-log.md` на каждый выполненный пункт.
5. Если пункт меняет согласованные решения (лимиты обхода в пунктах 9 и 11), перед правкой подтвердить значения с владельцем и обновить `.docs/phases/_status.md`.

## Что осталось за рамками плана

- Мелкие замечания (например, `pclose()` в `JobLauncher::launchWorker()` не проверяет код запуска — диагностика остаётся `queued` до 10 минут): записать отдельным пунктом после основных правок.
- Локальный фильтрующий прокси для Chrome (второй этап пункта 1).
- Аудит ИИ-пайплайна (`AiClient`, `Services/Ai/*`, отчёты) завершён по коду без критических дефектов. Параллельный субагентный аудит прерван по квоте, поэтому его вывод — мой собственный разбор файлов, а не отчёт субагента.
