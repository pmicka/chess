<?php

function score_defaults(): array
{
    return [
        'host_wins' => 0,
        'world_wins' => 0,
        'draws' => 0,
        'last_result' => null,
        'last_counted_game_id' => 0,
        'updated_at' => null,
    ];
}

function score_normalize(array $data): array
{
    $defaults = score_defaults();
    $normalized = $defaults;

    $normalized['host_wins'] = isset($data['host_wins']) && is_numeric($data['host_wins'])
        ? (int)$data['host_wins']
        : 0;
    $normalized['world_wins'] = isset($data['world_wins']) && is_numeric($data['world_wins'])
        ? (int)$data['world_wins']
        : 0;
    $normalized['draws'] = isset($data['draws']) && is_numeric($data['draws'])
        ? (int)$data['draws']
        : 0;
    $normalized['last_result'] = isset($data['last_result']) && is_string($data['last_result'])
        ? $data['last_result']
        : null;
    $normalized['last_counted_game_id'] = isset($data['last_counted_game_id']) && is_numeric($data['last_counted_game_id'])
        ? (int)$data['last_counted_game_id']
        : 0;
    $normalized['updated_at'] = isset($data['updated_at']) && is_string($data['updated_at'])
        ? $data['updated_at']
        : null;

    return $normalized;
}

/** SQLite baseline plus uniquely recorded results; the legacy JSON is untouched. */
function score_load(?PDO $db = null): array
{
    $db = $db ?? get_db();
    $score = $db->query('SELECT * FROM score_baseline WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$score) throw new RuntimeException('Score baseline missing.');
    unset($score['id']);
    foreach ($db->query('SELECT result,COUNT(*) AS total FROM game_results WHERE counted=1 GROUP BY result') as $row) {
        $key = ['host' => 'host_wins', 'world' => 'world_wins', 'draw' => 'draws'][$row['result']];
        $score[$key] = (int)$score[$key] + (int)$row['total'];
    }
    $latest = $db->query('SELECT game_id,result,created_at FROM game_results WHERE counted=1 ORDER BY game_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($latest) {
        $score['last_counted_game_id'] = max((int)$score['last_counted_game_id'], (int)$latest['game_id']);
        $score['last_result'] = $latest['result'];
        $score['updated_at'] = $latest['created_at'];
    }
    return score_normalize($score);
}

function score_increment(string $result, int $gameId, ?PDO $db = null): array
{
    $db = $db ?? get_db();
    if (!$db->inTransaction()) throw new RuntimeException('Result accounting requires the game transaction.');
    if (!in_array($result, ['host', 'world', 'draw'], true)) throw new InvalidArgumentException('Invalid result.');
    $cutoff = (int)$db->query('SELECT last_counted_game_id FROM score_baseline WHERE id=1')->fetchColumn();
    $db->prepare('INSERT INTO game_results(game_id,result,counted) VALUES(?,?,?) ON CONFLICT(game_id) DO NOTHING')
        ->execute([$gameId, $result, $gameId > $cutoff ? 1 : 0]);
    $check = $db->prepare('SELECT result FROM game_results WHERE game_id=?');
    $check->execute([$gameId]);
    if ($check->fetchColumn() !== $result) throw new RuntimeException('Conflicting recorded result; manual review required.');
    return score_load($db);
}

function record_detected_result(PDO $db, array $game, array $status): void
{
    if (empty($status['ok']) || empty($status['over'])) throw new RuntimeException('Cannot establish game result.');
    $winner = $status['winner'] ?? null;
    if (in_array($winner, ['w', 'b'], true)) {
        $result = $winner === ($game['host_color'] === 'white' ? 'w' : 'b') ? 'host' : 'world';
    } elseif (in_array($status['reason'] ?? '', ['draw', 'stalemate'], true)) {
        $result = 'draw';
    } else {
        throw new RuntimeException('Cannot establish game result.');
    }
    score_increment($result, (int)$game['id'], $db);
}
