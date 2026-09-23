<?php
/**
 * Приём правок из редактора (admin.html) и запись их прямо на сервер —
 * akvarena.ru
 * ------------------------------------------------------------------
 * Пока этот файл не настроен (см. EDITOR_KEY ниже) или недоступен —
 * admin.html сам, автоматически, продолжает работать по-старому:
 * предлагает скачать файл и загрузить его на хостинг вручную.
 * Ничего в admin.html для этого менять не нужно.
 * ------------------------------------------------------------------
 */

// -------- НАСТРОЙКА: ключ редактора --------
// Замените на свою собственную строку (чем длиннее и бессмысленнее — тем
// лучше, например случайный набор букв и цифр). Этот же ключ редактор
// один раз спросит у вас в браузере при первом сохранении.
const EDITOR_KEY = 'ИЗМЕНИТЕ_ЭТОТ_КЛЮЧ';
// --------------------------------------------

// Список файлов страниц, которые разрешено перезаписывать.
// Должен совпадать с файлами из pages[] в admin.html. Если добавляете
// в редактор новую страницу — впишите её имя и сюда тоже.
const ALLOWED_FILES = [
    'index.html',
    'akvarena-bassein.html', 'akvarena-spa.html', 'akvarena-zal.html',
    'akvarena-raspisanie-plavanie.html', 'akvarena-raspisanie-aqua.html', 'akvarena-raspisanie-zal.html',
    'akvarena-trener-bassein.html', 'akvarena-trener-zal.html',
    'akvarena-tariffs.html', 'akvarena-tariff-bassein.html', 'akvarena-tariff-klub.html',
    'akvarena-tariff-semya.html', 'akvarena-tariff-fitnes.html', 'akvarena-tariff-fitnes-spa.html',
    'akvarena-tariff-spa.html', 'akvarena-tariff-obuchenie.html', 'akvarena-tariff-dop.html',
];

// Разрешённые имена файлов для фотографий — по одному шаблону на раздел.
// Никакое другое имя (и тем более путь) сохранить не получится.
const PHOTO_PATTERNS = [
    '/^akvarena-trener-bassein-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
    '/^akvarena-trener-zal-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
    '/^akvarena-news-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
    '/^akvarena-pool-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
    '/^akvarena-spa-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
    '/^akvarena-zal-[a-z0-9]+(?:-[a-z0-9]+)*\.jpg$/',
];

header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'method_not_allowed');
}

// Ограничиваем размер тела запроса заранее, не дожидаясь его полного чтения.
$declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declaredLength > 8 * 1024 * 1024) {
    fail(413, 'too_large');
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 8 * 1024 * 1024) {
    fail(413, 'too_large');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fail(400, 'bad_json');
}

// Ключ проверяем и до, и после — но сначала убеждаемся, что он вообще задан
// (то есть файл настроен), иначе честно говорим об этом отдельной ошибкой.
if (EDITOR_KEY === 'ИЗМЕНИТЕ_ЭТОТ_КЛЮЧ') {
    fail(403, 'key_not_configured');
}

$key = (string)($data['key'] ?? '');
if (!hash_equals(EDITOR_KEY, $key)) {
    fail(403, 'bad_key');
}

$file = (string)($data['file'] ?? '');
if (!in_array($file, ALLOWED_FILES, true)) {
    fail(403, 'bad_file');
}

$html = (string)($data['html'] ?? '');
if ($html === '' || strlen($html) > 4 * 1024 * 1024) {
    fail(400, 'bad_html');
}
if (strpos($html, '<main') === false || strpos($html, '</main>') === false) {
    fail(400, 'bad_html_shape');
}

/**
 * Записывает файл атомарно: сначала во временный файл рядом, затем
 * переименовывает поверх цели. Так посетитель сайта никогда не увидит
 * наполовину записанную страницу, даже если запись прервётся.
 */
function safe_write(string $path, string $content): bool {
    $tmp = $path . '.tmp' . bin2hex(random_bytes(6));
    $fh = @fopen($tmp, 'wb');
    if (!$fh) {
        return false;
    }
    $ok = flock($fh, LOCK_EX) && (fwrite($fh, $content) !== false);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

$dir = __DIR__;

if (!safe_write($dir . '/' . $file, $html)) {
    fail(500, 'write_failed');
}

$savedPhotos = [];
$photos = is_array($data['photos'] ?? null) ? $data['photos'] : [];
if (count($photos) > 10) {
    fail(400, 'too_many_photos');
}

foreach ($photos as $p) {
    if (!is_array($p)) {
        fail(400, 'bad_photo_entry');
    }
    $name = (string)($p['name'] ?? '');

    // Имя должно совпасть с одним из разрешённых шаблонов целиком —
    // никаких "/", "\", ".." или прочих сюрпризов в имени файла.
    $okName = false;
    foreach (PHOTO_PATTERNS as $re) {
        if (preg_match($re, $name)) { $okName = true; break; }
    }
    if (!$okName) {
        fail(403, 'bad_photo_name');
    }

    $b64 = (string)($p['data'] ?? '');
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) === 0 || strlen($bin) > 6 * 1024 * 1024) {
        fail(400, 'bad_photo_data');
    }

    if (!safe_write($dir . '/' . $name, $bin)) {
        fail(500, 'photo_write_failed');
    }
    $savedPhotos[] = $name;
}

echo json_encode(['ok' => true, 'file' => $file, 'photos' => $savedPhotos], JSON_UNESCAPED_UNICODE);
