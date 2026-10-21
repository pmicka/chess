<?php
/** Public receipts never contain host capabilities or trigger another action. */
class Phase4MigrationRequired extends RuntimeException {}

function require_phase4(PDO $db): void
{
    if (!phase4_installed($db)) throw new Phase4MigrationRequired('Phase 4 migration required before writes. Reads remain available.');
}

function submission_id($value): ?string
{
    if ($value === null || $value === '') return null; // Older clients retain their existing contract.
    if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
        respond_json(400, ['ok' => false, 'error' => 'Invalid submission identifier.', 'code' => 'submission_invalid']);
    }
    return $value;
}

function submission_hash(string $actor, string $id, string $credential): string
{
    return hash('sha256', $actor . ':' . hash('sha256', $credential) . ':' . $id);
}

function submission_request_hash(array $data): string
{
    return hash('sha256', json_encode([$data['game_id'], $data['expected_revision'],
        $data['from'], $data['to'], $data['promotion']], JSON_THROW_ON_ERROR));
}

function move_receipt(PDO $db, string $actor, ?string $id, string $credential, array $request): ?array
{
    if ($id === null) return null;
    $query = $db->prepare('SELECT * FROM move_receipts WHERE submission_hash=? AND actor=?');
    $query->execute([submission_hash($actor, $id, $credential), $actor]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!hash_equals($row['request_hash'], submission_request_hash($request))) {
        if ($db->inTransaction()) $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Submission identifier already belongs to a different move.', 'code' => 'submission_conflict']);
    }
    return json_decode($row['response_json'], true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
}

function host_action_receipt(PDO $db, string $credential): ?array
{
    $query = $db->prepare("SELECT r.response_json FROM move_receipts r JOIN tokens t ON t.id=r.token_id
        WHERE r.actor='host' AND t.token=? AND t.used=1");
    $query->execute([$credential]);
    $json = $query->fetchColumn();
    if ($json !== false) return json_decode($json, true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
    $query = $db->prepare("SELECT g.id AS new_game_id FROM game_transitions x
        JOIN tokens t ON t.id=x.token_id JOIN games g ON g.id=x.successor_game_id
        WHERE t.token=? AND t.purpose='next_game' AND t.used=1");
    $query->execute([$credential]);
    $gameId = $query->fetchColumn();
    return $gameId === false ? null : ['ok' => true, 'replayed' => true, 'new_game_id' => (int)$gameId,
        'message' => 'Next game already created. Return to the public board.'];
}

function save_move_receipt(PDO $db, string $actor, ?string $id, string $credential, array $request, array $response, ?int $tokenId = null): void
{
    if ($id === null) return;
    if (!$db->inTransaction()) throw new RuntimeException('Receipt requires the move transaction.');
    unset($response['next_game_token']);
    $db->prepare('INSERT INTO move_receipts(submission_hash,actor,request_hash,token_id,game_id,source_revision,response_json) VALUES(?,?,?,?,?,?,?)')
        ->execute([submission_hash($actor, $id, $credential), $actor, submission_request_hash($request), $tokenId,
            $request['game_id'], $request['expected_revision'], json_encode($response, JSON_THROW_ON_ERROR)]);
}
