<?php
/**
 * Prometrius OBD2 — выдача карты «код ошибки → наименование».
 * ============================================================
 *
 * ЧТО ЭТО. Один открытый файл `data/dtc_map.bin` (формат PDTM: блочный,
 * zstd, с указателем в начале). Никакого шифрования: карта открытая —
 * в ней только код и наименование. Описания («кто виноват» и «что делать»)
 * этим путём НЕ ходят, у них отдельная доставка.
 *
 * ПОЧЕМУ PHP, А НЕ ПРОСТО СТАТИКА. Две причины, и обе — про поведение:
 *   1) СЧЁТЧИК. nginx не умеет считать отдачи статики; PHP умеет.
 *   2) ВЕРСИЯ. Файл лежит ВНЕ webroot-доступа (`data/` закрыт в nginx),
 *      поэтому отдать его можно только скриптом — и заодно посчитать.
 * ETag и Last-Modified считаем сами, чтобы приложение могло спрашивать
 * «а не изменилось ли» и получать 304 без тела.
 *
 * КОНТРАКТ С ПРИЛОЖЕНИЕМ (Android, кнопка «Скачать» / «Проверить обновление»):
 *   GET  ?                    → 200, байты карты, ETag + Last-Modified
 *   GET    + If-None-Match    → 304 без тела, если карта не менялась
 *   GET    + If-Modified-Since→ 304 без тела, если карта не менялась
 *   HEAD                      → те же заголовки, без тела (проверка обновления)
 *   GET  ?stat=1              → JSON {"file":"dtc_map.bin","downloads":N}
 *   карты нет                 → 503 + короткий текст (приложение скажет
 *                               «база недоступна» и НЕ тронет локальную)
 *
 * СЧЁТЧИК. Инкремент ТОЛЬКО на полную отдачу (200 + GET). 304 и HEAD не
 * считаются: иначе «обновлений нет» накручивало бы статистику, и цифра на
 * странице перестала бы означать «столько людей скачали базу».
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ:
 *   • Range-запросов. Файл один и заменяется целиком; докачка кусками
 *     усложнила бы и файл, и приложение, ничего не дав.
 *   • Ключей и подписи. Карта открытая по решению заказчика.
 */

declare(strict_types=1);

const MAP_FILE  = __DIR__ . '/data/dtc_map.bin';
const LOG_FILE  = __DIR__ . '/data/dtc_downloads.log';
const FILE_NAME = 'dtc_map.bin';

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
    // flock на чтение: писец может писать в этот момент
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

/** +1 к счётчику. Пишем ПОСЛЕДНЮЮ строку — при чтении берётся она. */
function dtc_bump(string $log): void
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
    fwrite($fp, sprintf("[%s] download file=%s count=%d\n", date('Y-m-d H:i:s'), FILE_NAME, $current));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
}

// ---- JSON-статистика для страницы сайта -------------------------------
if (isset($_GET['stat'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        ['file' => FILE_NAME, 'downloads' => dtc_downloads(LOG_FILE)],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---- карты нет: честный отказ ----------------------------------------
if (!is_file(MAP_FILE)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "DTC map is not available\n";
    exit;
}

$size    = (int)filesize(MAP_FILE);
$mtime   = (int)filemtime(MAP_FILE);
// ETag сильный и по содержимому: правка файла меняет его даже при совпадении mtime
$etag    = '"' . substr((string)hash_file('sha256', MAP_FILE), 0, 32) . '"';
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
    exit;
}

// ---- полная отдача: считаем и отдаём ---------------------------------
dtc_bump(LOG_FILE);

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . FILE_NAME . '"');
header('X-Dtc-Downloads: ' . dtc_downloads(LOG_FILE));

// Читаем кусками: файл небольшой, но не грузим его целиком в память без нужды
$fp = fopen(MAP_FILE, 'rb');
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
