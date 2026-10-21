<?php
/** Bounded, fail-closed bridge to the bundled server-side chess.js engine. */
class ChessStateException extends RuntimeException
{
    public string $responseCode;
    public int $httpStatus;

    public function __construct(string $code)
    {
        $this->responseCode = $code;
        $this->httpStatus = $code === 'illegal_move' ? 400 : 409;
        parent::__construct($code === 'illegal_move'
            ? 'Illegal move. Check the position and promotion choice.'
            : 'Stored position and history could not be verified. No move was saved; contact the site operator.');
    }
}

class ChessEngineUnavailable extends RuntimeException {}

function chess_engine_request(array $payload, int $timeoutMs = 3000): array
{
    $unavailable = 'Chess validation is temporarily unavailable. No move was saved; refresh before retrying.';
    $script = __DIR__ . '/../scripts/chess_rules.js';
    foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'stream_select'] as $function) {
        if (!function_exists($function)) throw new ChessEngineUnavailable($unavailable);
    }
    if (!is_file($script) || !is_file(__DIR__ . '/../assets/chess.min.js')) {
        throw new ChessEngineUnavailable($unavailable);
    }
    try {
        $input = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ChessStateException('state_inconsistent');
    }
    if (strlen($input) > 131072) throw new ChessStateException('state_inconsistent');
    $binary = defined('CHESS_NODE_BINARY') ? CHESS_NODE_BINARY : 'node';
    if (!is_string($binary) || $binary === '') throw new ChessEngineUnavailable($unavailable);
    // Array argv bypasses a shell. FEN/PGN are sent through stdin, never command
    // arguments; no credential or external URL is supplied to the subprocess.
    $pipes = [];
    try {
        $process = @proc_open([$binary, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    } catch (Throwable $e) {
        throw new ChessEngineUnavailable($unavailable);
    }
    if (!is_resource($process)) throw new ChessEngineUnavailable($unavailable);
    $output = '';
    $errorBytes = 0;
    $offset = 0;
    $exitCode = -1;
    $deadline = hrtime(true) + max(1, $timeoutMs) * 1000000;
    try {
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        while (true) {
            if (hrtime(true) >= $deadline) throw new ChessEngineUnavailable($unavailable);
            $read = [];
            foreach ([1, 2] as $i) if (!feof($pipes[$i])) $read[] = $pipes[$i];
            $write = isset($pipes[0]) ? [$pipes[0]] : [];
            $except = null;
            if ($read || $write) {
                if (@stream_select($read, $write, $except, 0, 50000) === false) throw new ChessEngineUnavailable($unavailable);
                foreach ($write as $pipe) {
                    $written = @fwrite($pipe, substr($input, $offset, 8192));
                    if ($written === false) throw new ChessEngineUnavailable($unavailable);
                    $offset += $written;
                    if ($offset === strlen($input)) { fclose($pipes[0]); unset($pipes[0]); }
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false) throw new ChessEngineUnavailable($unavailable);
                    if ($pipe === $pipes[1]) $output .= $chunk;
                    else $errorBytes += strlen($chunk);
                }
            } else {
                usleep(1000);
            }
            if (strlen($output) + $errorBytes > 131072) throw new ChessEngineUnavailable($unavailable);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $output .= stream_get_contents($pipes[1], 131073);
                $errorBytes += strlen(stream_get_contents($pipes[2], 131073));
                break;
            }
        }
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $status = proc_get_status($process);
        if ($status['running']) { proc_terminate($process); proc_terminate($process, 9); }
        proc_close($process);
    }
    if ($exitCode !== 0 || strlen($output) + $errorBytes > 131072) throw new ChessEngineUnavailable($unavailable);
    $result = json_decode($output, true);
    if (!is_array($result) || !is_bool($result['ok'] ?? null)) throw new ChessEngineUnavailable($unavailable);
    if ($result['ok'] !== true) {
        if (in_array($result['code'] ?? '', ['illegal_move', 'state_inconsistent'], true)) throw new ChessStateException($result['code']);
        throw new ChessEngineUnavailable($unavailable);
    }
    return $result;
}

function chess_status_is_valid($status): bool
{
    return is_array($status) && ($status['ok'] ?? null) === true && is_bool($status['over'] ?? null)
        && array_key_exists('reason', $status) && array_key_exists('winner', $status)
        && ($status['over']
            ? (($status['reason'] === 'checkmate' && in_array($status['winner'], ['w', 'b'], true))
                || (in_array($status['reason'], ['draw', 'stalemate'], true) && $status['winner'] === null))
            : ($status['reason'] === null && $status['winner'] === null));
}

function validate_game_move(array $game, string $from, string $to, string $promotion): array
{
    $result = chess_engine_request(['fen' => $game['fen'], 'pgn' => $game['pgn'], 'turn_color' => $game['turn_color'],
        'move' => ['from' => $from, 'to' => $to, 'promotion' => $promotion]]);
    if (!chess_status_is_valid($result['before'] ?? null)) throw new ChessEngineUnavailable('Chess validation returned an invalid response. No move was saved.');
    if ($result['before']['over']) return $result;
    if (!chess_status_is_valid($result['after'] ?? null) || !is_string($result['fen'] ?? null)
        || !is_string($result['pgn'] ?? null) || !is_array($result['move'] ?? null)
        || ($result['turn_color'] ?? null) !== ($game['turn_color'] === 'white' ? 'black' : 'white')
        || !is_string($result['move']['san'] ?? null) || $result['move']['san'] === ''
        || ($result['move']['from'] ?? null) !== $from || ($result['move']['to'] ?? null) !== $to
        || ($result['move']['promotion'] ?? '') !== $promotion) {
        throw new ChessEngineUnavailable('Chess validation returned an invalid response. No move was saved.');
    }
    return $result;
}
