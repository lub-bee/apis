<?php

// =========================
// CONFIG
// =========================

define('UNSPLASH_ACCESS_KEY', 'HebsLod5ucoRSBLAMLmT4zwCsdpKbc7EnICeOAprWys');
define('CACHE_DIR', getenv('DAILY_IMAGES_CACHE_DIR') ?: (__DIR__ . '/cache'));
define('CACHE_RETENTION_DAYS', 7);

define('UNSPLASH_ENDPOINT', 'https://api.unsplash.com/photos/random');
define('UNSPLASH_QUERY', 'nature,landscape');
define('UNSPLASH_ORIENTATION', 'landscape');
define('UNSPLASH_UTM_SOURCE', 'daily-images-api');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$debugInfo = [
    'cacheDir' => CACHE_DIR,
    'cacheDirExists' => null,
    'cacheDirWritable' => null,
    'cacheDirCreateOk' => null,
    'cacheDirCreateError' => null,
    'unsplashFetchOk' => null,
    'unsplashError' => null,
];

// =========================
// CORS
// =========================

$allowedOrigins = [
    '*', // OK tant que pas d'auth ni cookies
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =========================
// HELPERS
// =========================

function utcDate(string $modifier = 'now'): DateTimeImmutable {
    return new DateTimeImmutable($modifier, new DateTimeZone('UTC'));
}

function dateKey(DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d');
}

function cachePath(string $date): string {
    return CACHE_DIR . "/{$date}.json";
}

function withUtm(string $url): string {
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . http_build_query([
        'utm_source' => UNSPLASH_UTM_SOURCE,
        'utm_medium' => 'referral',
    ]);
}

function readCache(string $date): ?array {
    $path = cachePath($date);
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function writeCache(string $date, array $data): bool {
    $path = cachePath($date);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return file_put_contents($path, $json) !== false;
}

function listCacheFiles(): array {
    $files = glob(CACHE_DIR . '/*.json');
    if (!$files) return [];
    rsort($files);
    return $files;
}

function getLatestCached(): ?array {
    foreach (listCacheFiles() as $file) {
        $raw = file_get_contents($file);
        if ($raw === false) continue;
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json['imageUrl']) && !empty($json['blurUrl'])) {
            return $json;
        }
    }
    return null;
}

function acquireLock(): bool {
    $lockFile = CACHE_DIR . '/lock.txt';
    $now = time();

    if (file_exists($lockFile)) {
        $age = $now - filemtime($lockFile);
        if ($age < 30) return false;
    }

    return file_put_contents($lockFile, (string)$now) !== false;
}

function releaseLock(): void {
    @unlink(CACHE_DIR . '/lock.txt');
}

// =========================
// UNSPLASH FETCH (cURL)
// =========================

function fetchFromUnsplash(?string &$errorOut = null): ?array {
    $url = UNSPLASH_ENDPOINT . '?' . http_build_query([
        'query' => UNSPLASH_QUERY,
        'orientation' => UNSPLASH_ORIENTATION,
        'content_filter' => 'high',
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        $errorOut = 'curl_init_failed';
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Authorization: Client-ID ' . UNSPLASH_ACCESS_KEY,
            'Accept-Version: v1',
            'User-Agent: DailyBackgroundProxy/1.0'
        ],
    ]);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        $errorOut = $curlErr ? "curl_error:$curlErr" : "http_code:$httpCode";
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['id']) || empty($json['urls']['raw'])) {
        $errorOut = 'invalid_json';
        return null;
    }

    $rawUrl = $json['urls']['raw'];
    $photographerName = $json['user']['name'] ?? null;
    $photographerUrl = $json['user']['links']['html'] ?? null;
    $photoUrl = $json['links']['html'] ?? null;

    return [
        'unsplashPhotoId' => $json['id'],
        'imageUrl' => $rawUrl . '&w=2600&q=80',
        'blurUrl'  => $rawUrl . '&w=200&q=20',
        'credit' => [
            'photographerName' => $photographerName ?? 'Unknown',
            'photographerUrl' => $photographerUrl ? withUtm($photographerUrl) : null,
            'unsplashPhotoUrl' => $photoUrl ? withUtm($photoUrl) : null,
            'unsplashName' => 'Unsplash',
            'unsplashUrl' => withUtm('https://unsplash.com/'),
        ],
    ];
}

// =========================
// ENSURE CACHE FOR DATE
// =========================

function ensureDateCached(string $date, array &$debugInfo): ?array {
    $cached = readCache($date);
    if ($cached) return $cached;

    if (!acquireLock()) {
        // Un autre process refresh, on ne bloque pas
        return null;
    }

    $err = null;
    $data = fetchFromUnsplash($err);

    $debugInfo['unsplashFetchOk'] = $data ? true : false;
    $debugInfo['unsplashError'] = $data ? null : $err;

    if ($data) {
        $data['dateUtc'] = $date;
        writeCache($date, $data);
    }

    releaseLock();
    return $data;
}

// =========================
// BOOTSTRAP CACHE DIR
// =========================

if (!is_dir(CACHE_DIR)) {
    $created = @mkdir(CACHE_DIR, 0775, true);
    $debugInfo['cacheDirCreateOk'] = $created;
    if (!$created) {
        $err = error_get_last();
        $debugInfo['cacheDirCreateError'] = $err ? ($err['message'] ?? 'mkdir_failed') : 'mkdir_failed';
    }
}

$debugInfo['cacheDirExists'] = is_dir(CACHE_DIR);
$debugInfo['cacheDirWritable'] = is_writable(CACHE_DIR);

// =========================
// MAIN
// =========================

$today = utcDate('today');
$anchorDate = dateKey($today);

$dates = [
    'prev' => $today->modify('-1 day'),
    'current' => $today,
    'next' => $today->modify('+1 day')
];

$images = [];

foreach ($dates as $key => $dt) {
    $date = dateKey($dt);

    $data = ensureDateCached($date, $debugInfo);
    if (!$data) $data = readCache($date);

    if ($data) {
        $images[$key] = [
            'dateUtc' => $date,
            'imageUrl' => $data['imageUrl'],
            'blurUrl' => $data['blurUrl'],
            'credit' => $data['credit'] ?? null,
        ];
    }
}

// Fallback hard guarantee
if (empty($images['current'])) {
    $fallback = getLatestCached();
    if ($fallback) {
        $images['current'] = [
            'dateUtc' => $fallback['dateUtc'] ?? $anchorDate,
            'imageUrl' => $fallback['imageUrl'],
            'blurUrl' => $fallback['blurUrl'],
            'credit' => $fallback['credit'] ?? null,
        ];
    }
}

// =========================
// RESPONSE
// =========================

$validUntil = $today->modify('+1 day')->setTime(0, 0, 30)->format('Y-m-d\TH:i:s\Z');

$response = [
    'anchorDateUtc' => $anchorDate,
    'images' => (object)$images, // force object in JSON
    'validUntilUtc' => $validUntil
];

if ($debug) {
    $response['debug'] = $debugInfo;
}

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
header('ETag: daily-pack-' . $anchorDate);

echo json_encode($response, JSON_UNESCAPED_SLASHES);
