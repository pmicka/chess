<?php
/**
 * mail.php — minimal email helper with diagnostics
 */

/**
 * Append a capped log entry for email failures.
 */
function log_mail_failure(array $context): void
{
    $timestamp = gmdate('Y-m-d H:i:s');
    $parts = ['CHESS_MAIL_FAIL', "ts={$timestamp}"];
    foreach ($context as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $safeKey = preg_replace('/[^a-zA-Z0-9_:-]/', '_', (string)$key);
        $safeValue = preg_replace('/\s+/', ' ', (string)$value);
        $parts[] = "{$safeKey}={$safeValue}";
    }

    $line = implode(' ', $parts);
    error_log($line);

    $logPath = dirname(__DIR__) . '/data/mail_fail.log';
    $maxLines = 200;

    $existing = file_exists($logPath) ? @file($logPath, FILE_IGNORE_NEW_LINES) : [];
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing[] = $line;
    $trimmed = array_slice($existing, -1 * $maxLines);

    @file_put_contents($logPath, implode(PHP_EOL, $trimmed) . PHP_EOL);
}

/**
 * Send a plain-text email using PHP's native mail() with envelope-from.
 *
 * Returns ['ok' => bool, 'error' => string|null, 'diagnostic' => string|null].
 */
function send_notification_email(string $to, string $from, string $subject, string $body, array $options = []): array
{
    $response = [
        'ok' => false,
        'error' => null,
        'diagnostic' => null,
    ];

    $replyTo = $options['reply_to'] ?? $from;
    $envelopeFrom = $options['envelope_from'] ?? $from;
    $context = is_array($options['context'] ?? null) ? $options['context'] : [];

    $validTo = filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
    $validFrom = filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
    $validEnvelope = $envelopeFrom !== '' && filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) !== false;

    if (!$validTo || !$validFrom) {
        $response['error'] = 'Invalid to/from address';
        log_mail_failure(array_merge($context, [
            'error' => $response['error'],
            'to_valid' => $validTo ? '1' : '0',
            'from_valid' => $validFrom ? '1' : '0',
        ]));
        return $response;
    }

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $sanitizedSubject = str_replace(["\r", "\n"], ' ', $subject);

    $additionalParams = null;
    if ($validEnvelope) {
        $safeEnvelope = preg_replace('/[^a-zA-Z0-9_.+@-]/', '', $envelopeFrom);
        $additionalParams = $safeEnvelope !== '' ? '-f ' . $safeEnvelope : null;
    }

    $mailSent = false;
    $caughtError = null;

    try {
        if ($additionalParams !== null) {
            $mailSent = mail($to, $sanitizedSubject, $body, implode("\r\n", $headers), $additionalParams);
        } else {
            $mailSent = mail($to, $sanitizedSubject, $body, implode("\r\n", $headers));
        }
    } catch (Throwable $e) {
        $caughtError = $e->getMessage();
        $mailSent = false;
    }

    if (!$mailSent) {
        $lastPhpError = error_get_last();
        $diagnostic = $caughtError ?: ($lastPhpError['message'] ?? null) ?: null;
        $response['error'] = 'mail() returned false';
        $response['diagnostic'] = $diagnostic;

        log_mail_failure(array_merge($context, [
            'error' => $response['error'],
            'diag' => $diagnostic,
            'to' => $to,
            'from' => $from,
            'envelope' => $additionalParams,
        ]));

        return $response;
    }

    $response['ok'] = true;
    return $response;
}

