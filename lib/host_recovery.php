<?php

/**
 * A recognized unused link may request email recovery for seven days from
 * issuance. Expiry is relaxed ONLY here, never for board/move authorization.
 * Revocation, use, purpose, and game revision are checked before recovery.
 */
function validate_host_recovery_token(PDO $db, string $tokenValue): array
{
    $validation = validate_host_token($db, $tokenValue, false, true, null);
    if (($validation['ok'] ?? false) !== true) {
        return $validation;
    }

    $row = $validation['row'];
    $issued = (string)($row['created_at'] ?? '');
    $createdAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $issued, new DateTimeZone('UTC'));
    $now = datetime_utc();
    if (!$createdAt || $createdAt->format('Y-m-d H:i:s') !== $issued || $createdAt > $now) {
        return ['ok' => false, 'code' => 'invalid', 'row' => null];
    }
    if ($now >= $createdAt->add(new DateInterval('P7D'))) {
        return ['ok' => false, 'code' => 'expired', 'row' => null];
    }

    // Never recover another game or an older game, even if legacy data contains
    // multiple active rows. IDs identify creation order, not a mutable timestamp.
    $stmt = $db->prepare("
        SELECT id, host_color, turn_color, status, last_move_san, revision
        FROM games
        WHERE id = :id AND id = (SELECT MAX(id) FROM games)
        LIMIT 1
    ");
    $stmt->execute([':id' => $row['game_id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game || ($row['purpose'] === 'your_move'
        ? ($game['status'] !== 'active' || $game['turn_color'] !== $game['host_color'])
        : $game['status'] !== 'finished')) {
        return ['ok' => false, 'code' => 'invalid', 'row' => null];
    }

    return ['ok' => true, 'code' => $validation['code'], 'row' => $row, 'game' => $game];
}
