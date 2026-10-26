<?php
/**
 * Prometrius — счётчик скачиваний.
 * =================================
 *
 * ГДЕ ЛЕЖИТ ЛОГ — ГЛАВНОЕ ЗДЕСЬ.
 * -------------------------------------------------------------------------
 * Сначала лог лежал рядом со скриптом, то есть ВНУТРИ деплояемого дерева.
 * Из этого вышло две беды по очереди:
 *   1) файл приезжал из git под владельцем репозитория, а пишет в него
 *      www-data — прав на запись нет, и счётчик МОЛЧА не считал;
 *   2) когда файл убрали из git, `git pull` СТАЛ УДАЛЯТЬ его из рабочего
 *      дерева — и счётчик падал уже из-за отсутствия файла.
 *
 * ВЫВОД: состояние, которое пишет сервер, НЕ ДОЛЖНО жить в дереве, которое
 * перезаписывает деплой. Теперь лог в /var/lib/prometrius/ — вне репозитория и
 * вне webroot. Деплой его не касается, владелец ставится ОДИН раз:
 *
 *     sudo mkdir -p /var/lib/prometrius
 *     sudo chown www-data:www-data /var/lib/prometrius
 *
 * ПУТЬ МОЖНО ПЕРЕОПРЕДЕЛИТЬ переменной окружения PROMETRIUS_STATE_DIR.
 * Если каталога нет и создать его нечем (локальная разработка) — работает
 * ЗАПАСНОЙ путь рядом со скриптом, чтобы ничего не падало.
 *
 * ИСПОЛЬЗОВАНИЕ
 *   ?file=app_obd2-release.apk      — +1 и редирект на файл
 *   ?file=…&stat=1                  — JSON: сколько скачано, где лог, можно ли писать
 *   ?file=…&selftest=1              — пробная запись: внятный ответ
 *
 * ПОЧЕМУ РЕДИРЕКТ, А НЕ ОТДАЧА ФАЙЛА. Так nginx отдаёт APK сам (быстро, с
 * докачкой и кэшем), а PHP отвечает только за счёт. Отдача 2.4 МБ через PHP —
 * лишняя работа и память на каждый запрос.
 */

declare(strict_types=1);

/** Где живёт лог: сначала общий каталог состояния, иначе рядом со скриптом. */
function dtc_log_path(string $name): string
{
    $dir = getenv('PROMETRIUS_STATE_DIR');
    if ($dir === false || $dir === '') {
        $dir = '/var/lib/prometrius';
    }
    // Каталог есть и доступен на запись — штатный путь
    if (is_dir($dir) && is_writable($dir)) {
        return $dir . '/' . $name;
    }
    // Каталога нет — пробуем создать (сработает, если хватает прав)
    if (!is_dir($dir) && @mkdir($dir, 0775, true) && is_writable($dir)) {
        return $dir . '/' . $name;
    }
    // Запасной путь: рядом со скриптом (разработка, хостинг без /var/lib)
    return __DIR__ . '/' . $name;
}

$LOG_FILE = dtc_log_path('downloads.log');

// Имя файла. Только безопасные имена: без слешей и «..», иначе
// `file=../../etc/passwd` уводил бы редирект куда угодно.
$file = isset($_GET['file']) ? (string)$_GET['file'] : 'app_obd2-release.apk';
if (!preg_match('/^[A-Za-z0-9._-]+$/', $file) || strpos($file, '..') !== false) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'bad_file'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Последняя запись count=N для нужного файла. */
function dtc_read_count(string $log, string $file): int
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
        if (strpos($line, "file=$file") === false) {
            continue;
        }
        if (preg_match('/count=(\d+)/', $line, $m)) {
            $count = (int)$m[1];
        }
    }
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $count;
}

/**
 * +1 к счётчику. Возвращает true, если запись действительно удалась.
 * ЧТЕНИЕ И ЗАПИСЬ — ПОД ОДНОЙ блокировкой и из ОДНОГО дескриптора: иначе два
 * одновременных скачивания прочитали бы одно и то же число (потерянный +1),
 * а `filesize()`/второй `file()` на кэше stat могли дать ноль.
 */
function dtc_bump(string $log, string $file): bool
{
    $fp = @fopen($log, 'c+');           // c+ : создаёт при отсутствии, НЕ обрезает
    if ($fp === false) {
        return false;                    // чаще всего — нет прав на каталог
    }
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $current = 0;
    rewind($fp);
    while (($line = fgets($fp)) !== false) {
        if (strpos($line, "file=$file") === false) {
            continue;
        }
        if (preg_match('/count=(\d+)/', $line, $m)) {
            $current = (int)$m[1];
        }
    }
    $current++;

    $ok = true;
    $line = sprintf("[%s] download file=%s count=%d\n", date('Y-m-d H:i:s'), $file, $current);
    if (fseek($fp, 0, SEEK_END) !== 0 || fwrite($fp, $line) === false) {
        $ok = false;
    }
    @fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** Можно ли писать в лог — честный ответ ДО попытки скачивания. */
function dtc_writable(string $log): bool
{
    if (is_file($log)) {
        return is_writable($log);
    }
    return is_writable(dirname($log));
}

// ---- ПРОБНАЯ ЗАПИСЬ: один запрос, внятный ответ ----------------------
if (isset($_GET['selftest'])) {
    $before = dtc_read_count($LOG_FILE, $file);
    $ok = dtc_bump($LOG_FILE, $file);
    $after = dtc_read_count($LOG_FILE, $file);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'file'     => $file,
        'log'      => $LOG_FILE,
        'exists'   => is_file($LOG_FILE),
        'writable' => dtc_writable($LOG_FILE),
        'write_ok' => $ok,
        'before'   => $before,
        'after'    => $after,
        'verdict'  => ($ok && $after === $before + 1)
            ? 'ok: запись работает'
            : 'ПРОБЛЕМА: запись не удалась — проверьте права на ' . dirname($LOG_FILE) . ' (владелец www-data)',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- СТАТИСТИКА: число + честный ответ о записи ----------------------
if (isset($_GET['stat']) && $_GET['stat'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'file'      => $file,
        'downloads' => dtc_read_count($LOG_FILE, $file),
        'writable'  => dtc_writable($LOG_FILE),
        'log'       => $LOG_FILE,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- ОБЫЧНЫЙ ЗАПРОС: +1 и редирект на файл ---------------------------
dtc_bump($LOG_FILE, $file);            // результат не влияет на скачивание

$target = 'https://prometrius.com/downloads/' . $file;
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ' . $target, true, 302);
exit;
