<?php
/**
 * /admin/mail_test.php — protected mail() health check
 */

header('X-Robots-Tag: noindex');
header('Content-Type: application/json');

function respond_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'config']);
}

if (!defined('ADMIN_RESET_KEY') || ADMIN_RESET_KEY === '') {
    respond_json(503, ['ok' => false, 'error' => 'Server misconfigured: ADMIN_RESET_KEY not set', 'code' => 'admin_key_missing']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$providedKey = '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}

foreach ($headers as $name => $value) {
    if (strcasecmp((string)$name, 'X-Admin-Key') === 0) {
        $providedKey = (string)$value;
        break;
    }
}

if ($providedKey === '' && isset($_SERVER['HTTP_X_ADMIN_KEY'])) {
    $providedKey = (string)$_SERVER['HTTP_X_ADMIN_KEY'];
}

if ($providedKey === '' && isset($_POST['admin_key'])) {
    $providedKey = is_scalar($_POST['admin_key']) ? (string)$_POST['admin_key'] : '';
}

if ($providedKey === '') {
    respond_json(401, ['ok' => false, 'error' => 'Admin key missing', 'code' => 'admin_key_missing']);
}

if (!hash_equals(ADMIN_RESET_KEY, $providedKey)) {
    respond_json(403, ['ok' => false, 'error' => 'Forbidden', 'code' => 'forbidden']);
}

if (YOUR_EMAIL === '' || MAIL_FROM === '') {
    respond_json(503, ['ok' => false, 'error' => 'Email configuration incomplete', 'code' => 'mail_config']);
}

$timestamp = gmdate('Y-m-d H:i:s');
$subject = 'Mail health check — Me vs the World Chess';
$body = "Mail test triggered at {$timestamp} UTC\n"
    . "From IP: " . client_ip() . "\n";

$result = send_notification_email(
    YOUR_EMAIL,
    MAIL_FROM,
    $subject,
    $body,
    [
        'reply_to' => MAIL_FROM,
        'envelope_from' => MAIL_FROM,
        'context' => ['path' => 'admin_mail_test', 'ts' => $timestamp],
    ]
);

if (($result['ok'] ?? false) !== true) {
    respond_json(500, [
        'ok' => false,
        'error' => $result['error'] ?? 'Email failed',
        'diagnostic' => $result['diagnostic'] ?? null,
    ]);
}

respond_json(200, [
    'ok' => true,
    'message' => 'Mail sent',
    'to' => YOUR_EMAIL,
    'from' => MAIL_FROM,
    'timestamp' => $timestamp . ' UTC',
]);
