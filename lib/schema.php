<?php

function phase2_installed(PDO $db): bool
{
    $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='app_meta'")->fetchColumn();
    return $exists && in_array((int)$db->query('SELECT schema_version FROM app_meta WHERE id=1')->fetchColumn(), [2, 3], true);
}

function phase4_installed(PDO $db): bool
{
    return phase2_installed($db) && (int)$db->query('SELECT schema_version FROM app_meta WHERE id=1')->fetchColumn() === 3;
}

/** Additive, explicit migration. Never imports scores or changes existing tokens. */
function migrate_phase4(PDO $db): void
{
    if (phase4_installed($db)) return;
    if (!phase2_installed($db)) throw new RuntimeException('Phase 2 migration required first.');
    begin_write_transaction($db);
    try {
        if (phase4_installed($db)) { $db->commit(); return; }
        phase2_preflight($db);
        $db->exec("CREATE TABLE notification_outbox(
            id INTEGER PRIMARY KEY,token_id INTEGER NOT NULL UNIQUE REFERENCES tokens(id),
            game_id INTEGER NOT NULL REFERENCES games(id),game_revision INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sending','accepted','obsolete','failed')),
            attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),next_attempt_at INTEGER NOT NULL DEFAULT 0,
            lease_until INTEGER,claim TEXT,last_error TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,accepted_at TEXT)");
        $db->exec('CREATE INDEX outbox_due ON notification_outbox(status,next_attempt_at,lease_until)');
        $db->exec("CREATE TABLE move_receipts(
            submission_hash TEXT PRIMARY KEY,actor TEXT NOT NULL CHECK(actor IN ('visitor','host')),
            request_hash TEXT NOT NULL,token_id INTEGER UNIQUE REFERENCES tokens(id),
            game_id INTEGER NOT NULL REFERENCES games(id),source_revision INTEGER NOT NULL,
            response_json TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $db->exec('UPDATE app_meta SET schema_version=3 WHERE id=1');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Read-only preflight. Never repairs, deletes, or selects a winning game. */
function phase2_preflight(PDO $db): void
{
    if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('SQLite integrity check failed; restore or review before migration.');
    }
    if ((int)$db->query("SELECT COUNT(*) FROM games WHERE status='active'")->fetchColumn() > 1) {
        throw new RuntimeException('Multiple active games: resolve explicitly before migration.');
    }
    if ($db->query("SELECT 1 FROM games WHERE status='active' AND id<>(SELECT MAX(id) FROM games)")->fetchColumn()) {
        throw new RuntimeException('Active game is not the latest created game: review before migration.');
    }
    if ($db->query('SELECT 1 FROM locks GROUP BY game_id,name HAVING COUNT(*)>1 LIMIT 1')->fetchColumn()) {
        throw new RuntimeException('Duplicate locks: resolve explicitly before migration.');
    }
    if ($db->query("SELECT 1 FROM games WHERE host_color NOT IN ('white','black')
        OR visitor_color NOT IN ('white','black') OR host_color=visitor_color
        OR turn_color NOT IN ('white','black') OR status NOT IN ('active','finished') LIMIT 1")->fetchColumn()) {
        throw new RuntimeException('Invalid game metadata: resolve explicitly before migration.');
    }
    foreach ($db->query('SELECT fen,turn_color FROM games') as $game) {
        $parts = preg_split('/\s+/', trim($game['fen']));
        if (($parts[1] ?? '') !== ($game['turn_color'] === 'white' ? 'w' : 'b')) {
            throw new RuntimeException('FEN and stored turn disagree: review before migration.');
        }
    }
    if ($db->query('PRAGMA foreign_key_check')->fetch()) {
        throw new RuntimeException('Foreign-key violations: resolve explicitly before migration.');
    }
}

/** Explicit migration only. Legacy score totals are imported, never recomputed. */
function migrate_phase2(PDO $db, array $baseline): void
{
    if (phase2_installed($db)) {
        return;
    }
    $db->beginTransaction();
    try {
        // Obtain the write reservation before reading the migration preconditions.
        $db->exec('UPDATE games SET id=id WHERE 0');
        if (phase2_installed($db)) { $db->commit(); return; }
        phase2_preflight($db);
        $db->exec("ALTER TABLE games ADD COLUMN revision INTEGER NOT NULL DEFAULT 0 CHECK(typeof(revision)='integer' AND revision>=0)");
        $db->exec("ALTER TABLE tokens ADD COLUMN game_revision INTEGER CHECK(game_revision IS NULL OR (typeof(game_revision)='integer' AND game_revision>=0))");
        $db->exec('ALTER TABLE tokens ADD COLUMN expires_at TEXT');
        $db->exec('ALTER TABLE tokens ADD COLUMN revoked_at TEXT');
        // The issuing turn of legacy capabilities cannot be established safely.
        // Keep every original row/value, but do not grant legacy links new authority.
        $db->exec('UPDATE tokens SET revoked_at=CURRENT_TIMESTAMP');
        $db->exec("CREATE UNIQUE INDEX games_one_active ON games(status) WHERE status='active'");
        $db->exec('CREATE UNIQUE INDEX locks_game_name ON locks(game_id,name)');
        $db->exec('CREATE INDEX tokens_game_revision ON tokens(game_id,game_revision,purpose,used)');
        $db->exec('CREATE TABLE app_meta(id INTEGER PRIMARY KEY CHECK(id=1),schema_version INTEGER NOT NULL)');
        $db->exec('INSERT INTO app_meta VALUES(1,2)');
        $db->exec("CREATE TABLE score_baseline(
            id INTEGER PRIMARY KEY CHECK(id=1),host_wins INTEGER NOT NULL CHECK(host_wins>=0),
            world_wins INTEGER NOT NULL CHECK(world_wins>=0),draws INTEGER NOT NULL CHECK(draws>=0),
            last_counted_game_id INTEGER NOT NULL CHECK(last_counted_game_id>=0),
            last_result TEXT,updated_at TEXT)");
        $insert = $db->prepare('INSERT INTO score_baseline VALUES(1,?,?,?,?,?,?)');
        $insert->execute([$baseline['host_wins'], $baseline['world_wins'], $baseline['draws'],
            $baseline['last_counted_game_id'], $baseline['last_result'], $baseline['updated_at']]);
        $db->exec("CREATE TABLE game_results(
            game_id INTEGER PRIMARY KEY REFERENCES games(id),
            result TEXT NOT NULL CHECK(result IN ('host','world','draw')),
            counted INTEGER NOT NULL CHECK(counted IN (0,1)),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE game_transitions(
            completed_game_id INTEGER PRIMARY KEY REFERENCES games(id),
            successor_game_id INTEGER NOT NULL UNIQUE REFERENCES games(id),
            token_id INTEGER NOT NULL UNIQUE REFERENCES tokens(id),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(completed_game_id<>successor_game_id))");
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $suffix = strtolower($operation);
            $db->exec("CREATE TRIGGER games_metadata_{$suffix} BEFORE {$operation} ON games
                WHEN NEW.host_color NOT IN ('white','black') OR NEW.visitor_color NOT IN ('white','black')
                OR NEW.host_color=NEW.visitor_color OR NEW.turn_color NOT IN ('white','black')
                OR NEW.status NOT IN ('active','finished')
                BEGIN SELECT RAISE(ABORT,'Invalid game metadata'); END");
            $db->exec("CREATE TRIGGER tokens_binding_{$suffix} BEFORE {$operation} ON tokens
                WHEN NEW.revoked_at IS NULL AND (NEW.game_revision IS NULL OR NEW.expires_at IS NULL)
                BEGIN SELECT RAISE(ABORT,'Token binding and expiry required'); END");
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Missing/corrupt score data must be an operator decision, not silent zeroing. */
function read_legacy_score(string $path): array
{
    $handle = @fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Explicit readable score baseline required.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot lock score baseline.');
        $raw = stream_get_contents($handle);
        $score = json_decode((string)$raw, true);
        if (!is_array($score)) throw new RuntimeException('Invalid score baseline JSON.');
        foreach (['host_wins','world_wins','draws','last_counted_game_id'] as $key) {
            if (!isset($score[$key]) || !is_int($score[$key]) || $score[$key] < 0) {
                throw new RuntimeException('Invalid score baseline counters.');
            }
        }
        $score['last_result'] = $score['last_result'] ?? null;
        $score['updated_at'] = $score['updated_at'] ?? null;
        if (!in_array($score['last_result'], [null,'host','world','draw'], true)
            || ($score['updated_at'] !== null && !is_string($score['updated_at']))) {
            throw new RuntimeException('Invalid score baseline metadata.');
        }
        return $score;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
