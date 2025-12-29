<?php

const SCORE_FILE_PATH = __DIR__ . '/../data/score.json';

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

function score_file_path(): string
{
    $dir = dirname(SCORE_FILE_PATH);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return SCORE_FILE_PATH;
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

function score_load(): array
{
    $path = score_file_path();
    $handle = @fopen($path, 'c+');

    if ($handle === false) {
        error_log('score_load open_failed path=' . $path);
        return score_defaults();
    }

    $locked = flock($handle, LOCK_SH);
    if (!$locked) {
        fclose($handle);
        error_log('score_load lock_failed path=' . $path);
        return score_defaults();
    }

    clearstatcache(true, $path);
    rewind($handle);
    $contents = stream_get_contents($handle);
    $decoded = json_decode((string)$contents, true);
    if (!is_array($decoded)) {
        error_log('score_load parse_failed path=' . $path);
        $decoded = score_defaults();
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return score_normalize($decoded);
}

function score_save(array $score): void
{
    $path = score_file_path();
    $handle = @fopen($path, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Failed to open score file for writing: ' . $path);
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Failed to lock score file: ' . $path);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $normalized = score_normalize($score);
    $normalized['updated_at'] = $now->format('Y-m-d H:i:s');

    $json = json_encode($normalized, JSON_PRETTY_PRINT);
    if ($json === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw new RuntimeException('Failed to encode score JSON.');
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $json);
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);
}

function score_increment(string $result, int $gameId): array
{
    $valid = ['host', 'world', 'draw'];
    if (!in_array($result, $valid, true)) {
        throw new InvalidArgumentException('Invalid result: ' . $result);
    }

    $score = score_load();

    if ($gameId <= ($score['last_counted_game_id'] ?? 0)) {
        error_log(sprintf(
            'score_increment skipped game_id=%d last_counted=%d',
            $gameId,
            $score['last_counted_game_id'] ?? 0
        ));
        return $score;
    }

    if ($result === 'host') {
        $score['host_wins']++;
    } elseif ($result === 'world') {
        $score['world_wins']++;
    } else {
        $score['draws']++;
    }

    $score['last_result'] = $result;
    $score['last_counted_game_id'] = $gameId;

    score_save($score);

    return $score;
}
