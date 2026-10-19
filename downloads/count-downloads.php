<?php
/**
 * Простой счётчик скачиваний APK.
 * Записывает каждое скачивание в файл downloads.log.
 * Формат: [timestamp] download count=N
 *
 * Использование:
 *   https://prometrius.com/downloads/count-downloads.php?file=app_obd2-release.apk
 *
 * Чтобы посмотреть текущее количество:
 *   https://prometrius.com/downloads/count-downloads.php?file=app_obd2-release.apk&stat=1
 */

// Файл с логом скачиваний
$LOG_FILE = __DIR__ . '/downloads.log';

// Получаем имя файла
$file = $_GET['file'] ?? 'app_obd2-release.apk';

// Если stat=1 — просто выводим текущий счёт
if (isset($_GET['stat']) && $_GET['stat'] === '1') {
    $count = 0;
    if (file_exists($LOG_FILE)) {
        $lines = file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, "file=$file") !== false) {
                if (preg_match('/count=(\d+)/', $line, $matches)) {
                    $count = (int)$matches[1];
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['file' => $file, 'downloads' => $count]);
    exit;
}

// Инкремент счётчика
if (!file_exists($LOG_FILE)) {
    touch($LOG_FILE);
}

$timestamp = date('Y-m-d H:i:s');
$line = sprintf("[%s] download file=%s count=%d\n", $timestamp, $file, 0);

// Используем flock для безопасной записи
$fp = fopen($LOG_FILE, 'a');
if ($fp) {
    flock($fp, LOCK_EX);
    
    // Считаем текущее количество
    $current = 0;
    if (filesize($LOG_FILE) > 0) {
        $lines = file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            if (strpos($l, "file=$file") !== false) {
                if (preg_match('/count=(\d+)/', $l, $m)) {
                    $current = (int)$m[1];
                }
            }
        }
    }
    
    $current++;
    $new_line = sprintf("[%s] download file=%s count=%d\n", $timestamp, $file, $current);
    
    // Заменяем последнюю запись для этого файла или добавляем новую
    // Проще: просто добавляем, а при чтении берём последнюю запись
    fwrite($fp, $new_line);
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Перенаправляем на реальный файл APK
$apk_url = 'https://prometrius.com/downloads/' . basename($file);
header('Location: ' . $apk_url);
exit;
