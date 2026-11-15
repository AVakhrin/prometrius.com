<?php
/**
 * Prometrius OBD2 — выдача баз DTC: карты и описаний.
 * ====================================================
 *
 * ЧТО ЭТО. Один скрипт на ВСЕ файлы базы из каталога `data/`:
 *   • `dtc_map.bin`              — карта «код → наименование» (формат PDTM);
 *   • `dtc_desc_generic.bin`     — описания общих кодов (формат PDTD);
 *   • `dtc_desc_<марка>.bin`     — описания по марке (формат PDTD).
 *
 * ПОЧЕМУ ОДИН СКРИПТ, А НЕ ПАПКА СТАТИКИ. Три причины, и все про поведение:
 *   1) СЧЁТЧИК. nginx не умеет считать отдачи статики; PHP умеет. Счётчик нужен
 *      на КАЖДЫЙ файл отдельно: «сколько раз скачали базу GM» — это другой
 *      вопрос, чем «сколько раз скачали карту».
 *   2) УНИКАЛЬНЫЕ УСТРОЙСТВА. Приложение присылает `X-Device-Id` (этикетка
 *      устройства, не секрет). Без скрипта это некуда положить.
 *   3) ВЕРСИЯ. Файлы лежат ВНЕ webroot-доступа (`data/` закрыт в nginx),
 *      поэтому отдать их можно только скриптом — и заодно посчитать.
 * ETag и Last-Modified считаем сами, чтобы приложение могло спрашивать
 * «а не изменилось ли» и получать 304 без тела.
 *
 * КОНТРАКТ С ПРИЛОЖЕНИЕМ (Android, кнопка «Скачать» / «Проверить обновление»):
 *   GET  ?                       → 200, байты карты (по умолчанию `dtc_map.bin`)
 *   GET  ?file=dtc_desc_gm.bin   → 200, байты указанного файла
 *   GET    + If-None-Match       → 304 без тела, если файл не менялся
 *   GET    + If-Modified-Since   → 304 без тела, если файл не менялся
 *   HEAD                         → те же заголовки, без тела
 *   GET  ?stat=1                 → JSON: все файлы, скачивания, уникальные
 *   GET  ?stat=1&file=<имя>      → JSON по одному файлу
 *   файла нет / имя не из списка → 404 (и НИКОГДА — чужой файл с сервера)
 *
 * СЧЁТЧИК. Инкремент ТОЛЬКО на полную отдачу (200 + GET). 304 и HEAD не
 * считаются: иначе «обновлений нет» накручивало бы статистику, и цифра
 * перестала бы означать «столько раз скачали».
 *
 * УНИКАЛЬНЫЕ — ПО `X-Device-Id`. Считаем РАЗНЫЕ этикетки, а не запросы:
 * человек, проверивший обновление трижды, — одно устройство. Пустая или
 * отсутствующая этикетка в уникальные НЕ попадает (иначе «боты» и «неизвестные»
 * раздували бы цифру), но в общий счётчик скачивание попадает всегда.
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ:
 *   • Range-запросов. Файлы небольшие и заменяются целиком; докачка кусками
 *     усложнила бы и файл, и приложение, ничего не дав.
 *   • Ключей и подписи. Карта открытая по решению заказчика; описания по
 *     маркам — предмет подписки на уровне выдачи (см. отдельную задачу).
 *   • Обхода каталога. Имя файла берётся ТОЛЬКО из белого списка, собранного
 *     по факту наличия файлов; `..`, слэши и любые посторонние имена отсекаются
 *     `basename()` и проверкой по списку. Это не «дополнительная защита», а
 *     единственный способ вообще принимать имя файла из запроса.
 */

declare(strict_types=1);

/** Каталог с файлами базы. Закрыт для прямого доступа в nginx. */
const DATA_DIR = __DIR__ . '/data';

/** Карта — файл по умолчанию: старые версии приложения ходят без `?file=`. */
const MAP_FILE_NAME = 'dtc_map.bin';

/**
 * ЛОГИ СЧЁТЧИКОВ — ВНЕ ДЕПЛОЯЕМОГО ДЕРЕВА.
 *
 * ПОЧЕМУ. Пока лог лежал внутри деплоя, было две беды по очереди: файл
 * приезжал из git под владельцем репозитория и был недоступен на запись для
 * www-data (счётчик молча не считал), а после исключения из git `git pull`
 * СТАЛ УДАЛЯТЬ его из рабочего дерева. Состояние, которое пишет сервер, не
 * должно жить там, где распоряжается деплой.
 *
 * ВЛАДЕЛЕЦ СТАВИТСЯ ОДИН РАЗ:
 *     sudo mkdir -p /var/lib/prometrius
 *     sudo chown www-data:www-data /var/lib/prometrius
 */
function dtc_state_dir(): string
{
    $dir = getenv('PROMETRIUS_STATE_DIR');
    if ($dir === false || $dir === '') {
        $dir = '/var/lib/prometrius';
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    if (!is_dir($dir) && @mkdir($dir, 0775, true) && is_writable($dir)) {
        return $dir;
    }
    // запасной путь: рядом с данными (разработка)
    return DATA_DIR;
}

function dtc_log_path(string $file): string
{
    // ОДИН ЛОГ НА ФАЙЛ: иначе разбор «сколько скачали GM» требовал бы читать
    // общий поток и фильтровать его целиком.
    return dtc_state_dir() . '/dtc_downloads_' . dtc_log_key($file) . '.log';
}

function dtc_unique_path(string $file): string
{
    return dtc_state_dir() . '/dtc_unique_' . dtc_log_key($file) . '.log';
}

/** Имя файла → безопасный ключ лога (`.` → `_`): `dtc_map.bin` → `dtc_map_bin`. */
function dtc_log_key(string $file): string
{
    return preg_replace('/[^a-z0-9_]/i', '_', $file) ?? $file;
}

/**
 * БЕЛЫЙ СПИСОК ФАЙЛОВ — ПО ФАКТУ НАЛИЧИЯ.
 *
 * Так новые марки не требуют правки кода: положили `dtc_desc_kia.bin` —
 * он сразу доступен. При этом посторонний файл в `data/` не станет доступен,
 * потому что имя обязано начинаться с `dtc_` и кончаться `.bin`.
 */
function dtc_allowed_files(): array
{
    $out = [];
    foreach (glob(DATA_DIR . '/dtc_*.bin') ?: [] as $path) {
        $name = basename($path);
        if (preg_match('/^dtc_[a-z0-9_]+\.bin$/', $name) === 1) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

/** Имя запрошенного файла — ТОЛЬКО из белого списка. */
function dtc_requested_file(array $allowed): string
{
    $raw = isset($_GET['file']) ? (string)$_GET['file'] : MAP_FILE_NAME;
    // basename() отсекает `../` и слэши ДО сравнения — иначе проверка по списку
    // пропускала бы `dir/../dtc_map.bin` и подобное.
    $name = basename($raw);
    if (!in_array($name, $allowed, true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Unknown base file\n";
        exit;
    }
    return $name;
}

/** Текущее число скачиваний: читаем ПОСЛЕДНЮЮ строку с count=N. */
function dtc_downloads(string $log): int
{
    if (!is_file($log)) {
        return 0;
    }
    $count = 0;
    $fp = @fopen($log, 'r');
    if ($fp === false) {
        return 0;
    }
    @flock($fp, LOCK_SH);
    while (($line = fgets($fp)) !== false) {
        if (preg_match('/count=(\d+)/', $line, $m)) {
            $count = (int)$m[1];
        }
    }
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $count;
}

/** +1 к счётчику файла. Пишем ПОСЛЕДНЮЮ строку — при чтении берётся она. */
function dtc_bump(string $log, string $file): void
{
    $fp = @fopen($log, 'a');
    if ($fp === false) {
        return; // не смогли записать — отдачу файла это не должно ломать
    }
    @flock($fp, LOCK_EX);
    $current = 0;
    // перечитываем под эксклюзивной блокировкой, чтобы не потерять чужие +1
    $rp = @fopen($log, 'r');
    if ($rp !== false) {
        while (($line = fgets($rp)) !== false) {
            if (preg_match('/count=(\d+)/', $line, $m)) {
                $current = (int)$m[1];
            }
        }
        fclose($rp);
    }
    $current++;
    fwrite($fp, sprintf("[%s] download file=%s count=%d\n", date('Y-m-d H:i:s'), $file, $current));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * УЧЁТ УНИКАЛЬНЫХ УСТРОЙСТВ: файл-накопитель этикеток, по одной в строке.
 *
 * ПОЧЕМУ ФАЙЛ, А НЕ БАЗА. Уникальных — тысячи, а не миллионы; линейный поиск по
 * файлу дешевле, чем поднимать СУБД ради одного счётчика. Если однажды станет
 * тесно — здесь поменяется только эта функция, контракт с приложением не тронут.
 */
function dtc_unique_devices(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $lines === false ? [] : $lines;
}

/** Добавить этикетку, если её ещё нет. Возвращает true, если устройство новое. */
function dtc_unique_add(string $path, string $deviceId): bool
{
    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        return false; // без этикетки уникальность не считаем
    }
    $fp = @fopen($path, 'a+');
    if ($fp === false) {
        return false;
    }
    @flock($fp, LOCK_EX);
    $known = false;
    rewind($fp);
    while (($line = fgets($fp)) !== false) {
        if (trim($line) === $deviceId) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        rewind($fp);
        // fseek в конец: после чтения указатель может стоять в середине
        fseek($fp, 0, SEEK_END);
        fwrite($fp, $deviceId . "\n");
        @fflush($fp);
    }
    @flock($fp, LOCK_UN);
    fclose($fp);
    return !$known;
}

$allowed = dtc_allowed_files();

// ---- JSON-статистика для страницы сайта -------------------------------
if (isset($_GET['stat'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $files = [];
    foreach ($allowed as $name) {
        $log = dtc_log_path($name);
        $uniq = dtc_unique_path($name);
        $logDir = dirname($log);
        $files[$name] = [
            'downloads' => dtc_downloads($log),
            // `unique` — РАЗНЫЕ устройства по `X-Device-Id`
            'unique'    => count(dtc_unique_devices($uniq)),
            // `writable` — ЧЕСТНЫЙ ОТВЕТ О ЗАПИСИ. Без него счётчик, которому
            // не хватило прав, выглядит как «никто не скачивает».
            'writable'  => is_file($log) ? is_writable($log) : is_writable($logDir),
            'size'      => is_file(DATA_DIR . '/' . $name)
                ? (int)filesize(DATA_DIR . '/' . $name)
                : 0,
        ];
    }

    // `?stat=1&file=…` — ответ по одному файлу (как раньше, но с unique)
    if (isset($_GET['file'])) {
        $name = basename((string)$_GET['file']);
        if (!isset($files[$name])) {
            http_response_code(404);
            echo json_encode(['error' => 'unknown file'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(
            ['file' => $name] + $files[$name],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    echo json_encode(
        ['files' => $files, 'log' => dtc_state_dir()],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---- ФАЙЛ: выбираем из белого списка ---------------------------------
$file   = dtc_requested_file($allowed);
$path   = DATA_DIR . '/' . $file;

// ---- файла нет: честный отказ ----------------------------------------
if (!is_file($path)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "DTC base is not available\n";
    exit;
}

$size    = (int)filesize($path);
$mtime   = (int)filemtime($path);
// ETag сильный и по содержимому: правка файла меняет его даже при совпадении mtime
$etag    = '"' . substr((string)hash_file('sha256', $path), 0, 32) . '"';
$lastmod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

header('ETag: ' . $etag);
header('Last-Modified: ' . $lastmod);
// no-cache НЕ запрещает кэш, а требует ПЕРЕПРОВЕРКИ — именно она даёт 304
header('Cache-Control: no-cache');
header('Accept-Ranges: none');

// ---- условный запрос: 304 без тела -----------------------------------
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ifModified  = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

$notModified = false;
if ($ifNoneMatch !== '') {
    // допускаем и W/"...", и список через запятую
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        if (trim($candidate) === $etag) {
            $notModified = true;
            break;
        }
    }
} elseif ($ifModified !== '') {
    $since = strtotime($ifModified);
    // сравниваем по секундам: Last-Modified всегда «до» изменения файла
    $notModified = ($since !== false && $since >= $mtime);
}

if ($notModified) {
    http_response_code(304);
    exit;
}

// ---- HEAD: заголовки без тела, БЕЗ инкремента ------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    header('X-Dtc-Downloads: ' . dtc_downloads(dtc_log_path($file)));
    header('X-Dtc-Unique: ' . count(dtc_unique_devices(dtc_unique_path($file))));
    exit;
}

// ---- полная отдача: считаем и отдаём ---------------------------------
$log = dtc_log_path($file);
$uniq = dtc_unique_path($file);

dtc_bump($log, $file);

// УНИКАЛЬНОСТЬ — ДО ОТДАЧИ, но ПОСЛЕ инкремента: заголовок должен быть точным
// на момент ответа. Ошибка учёта отдачу НЕ ломает (функция возвращает false).
$deviceId = (string)($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
dtc_unique_add($uniq, $deviceId);

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . $file . '"');
header('X-Dtc-Downloads: ' . dtc_downloads($log));
header('X-Dtc-Unique: ' . count(dtc_unique_devices($uniq)));

// Читаем кусками: файлы небольшие, но не грузим их целиком в память без нужды
$fp = fopen($path, 'rb');
if ($fp !== false) {
    while (!feof($fp)) {
        $chunk = fread($fp, 65536);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
    fclose($fp);
}
