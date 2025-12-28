<?php
/**
 * /api/health.php — Deployment health check
 */

header('Content-Type: application/json');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond(503, ['ok' => false, 'reason' => $e->getMessage(), 'code' => 'config_load']);
}

log_db_path_info('health');

$pathInfo = null;

try {
    $pathInfo = ensure_db_path_ready(true);
} catch (RuntimeException $e) {
    respond(503, [
        'ok' => false,
        'reason' => $e->getMessage(),
        'code' => 'db_path',
        'db_path' => [
            'configured' => DB_PATH,
            'dir_exists' => $pathInfo['dir_exists'] ?? false,
            'dir_writable' => $pathInfo['dir_writable'] ?? false,
            'file_exists' => $pathInfo['exists'] ?? false,
        ],
        'turnstile_secret_loaded' => defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '',
    ]);
}

$pathInfo = resolve_db_path_info();
if (empty($pathInfo['exists'])) {
    respond(503, [
        'ok' => false,
        'reason' => 'Database file is missing.',
        'code' => 'db_missing',
        'db_path' => [
            'configured' => DB_PATH,
            'dir_exists' => $pathInfo['dir_exists'] ?? false,
            'dir_writable' => $pathInfo['dir_writable'] ?? false,
            'file_exists' => $pathInfo['exists'] ?? false,
        ],
        'turnstile_secret_loaded' => defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '',
    ]);
}

try {
    $db = get_db();
    $db->query('SELECT 1');
} catch (RuntimeException $e) {
    respond(503, [
        'ok' => false,
        'reason' => $e->getMessage(),
        'code' => 'db_path',
        'db_path' => [
            'configured' => DB_PATH,
            'dir_exists' => $pathInfo['dir_exists'] ?? false,
            'dir_writable' => $pathInfo['dir_writable'] ?? false,
            'file_exists' => $pathInfo['exists'] ?? false,
        ],
        'turnstile_secret_loaded' => defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '',
    ]);
} catch (Throwable $e) {
    respond(503, [
        'ok' => false,
        'reason' => 'Database unavailable',
        'code' => 'db_connect',
        'db_path' => [
            'configured' => DB_PATH,
            'dir_exists' => $pathInfo['dir_exists'] ?? false,
            'dir_writable' => $pathInfo['dir_writable'] ?? false,
            'file_exists' => $pathInfo['exists'] ?? false,
        ],
        'turnstile_secret_loaded' => defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '',
    ]);
}

respond(200, [
    'ok' => true,
    'db_path' => [
        'configured' => DB_PATH,
        'dir_exists' => $pathInfo['dir_exists'] ?? false,
        'dir_writable' => $pathInfo['dir_writable'] ?? false,
        'file_exists' => $pathInfo['exists'] ?? false,
    ],
    'db_connect' => true,
    'turnstile_secret_loaded' => defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '',
]);
