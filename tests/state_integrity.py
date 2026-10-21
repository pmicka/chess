#!/usr/bin/env python3
"""Phase 2 regressions, including the Phase 1 authorization suite."""

import concurrent.futures
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import secrets
import socket
import time
import unittest

import host_authorization as auth
START_FEN = auth.START_FEN


LEGACY_SCHEMA = """
CREATE TABLE games(id INTEGER PRIMARY KEY AUTOINCREMENT,host_color TEXT NOT NULL,
visitor_color TEXT NOT NULL,turn_color TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'active',
fen TEXT NOT NULL,pgn TEXT NOT NULL,last_move_san TEXT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,game_id INTEGER NOT NULL,
token TEXT NOT NULL UNIQUE,purpose TEXT NOT NULL,used INTEGER NOT NULL DEFAULT 0,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,used_at DATETIME,
FOREIGN KEY(game_id) REFERENCES games(id) ON DELETE CASCADE);
CREATE TABLE locks(id INTEGER PRIMARY KEY AUTOINCREMENT,game_id INTEGER NOT NULL,
name TEXT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY(game_id) REFERENCES games(id) ON DELETE CASCADE);
"""


class StateIntegrity(auth.HostAuthorization):
    def php_script(self, code):
        probe = self.app / 'probe.php'
        probe.write_text('<?php\n' + code)
        return subprocess.run(self.php_options + [str(probe)], capture_output=True, text=True)

    @contextmanager
    def second_worker(self):
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        environment = dict(os.environ)
        environment.pop('PHP_CLI_SERVER_WORKERS', None)
        worker = subprocess.Popen(self.php_options + ['-S', f'127.0.0.1:{port}', '-t', str(self.app)],
                                  env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.02)
            else: self.fail('Second isolated PHP worker did not start')
            other = StateIntegrity()
            other.base = f'http://127.0.0.1:{port}/'
            yield other
        finally:
            worker.terminate()
            try: worker.wait(timeout=5)
            except subprocess.TimeoutExpired:
                worker.kill(); worker.wait()

    def visitor(self, *, revision=None, game_id=1, source='e7', target='e5', san='e5'):
        if revision is None:
            revision = self.execute('SELECT revision FROM games WHERE id=?', (game_id,))[0]['revision']
        return self.request('api/visitor_move.php', {
            'game_id': game_id, 'expected_revision': revision, 'from': source, 'to': target,
            'last_move_san': san, 'turnstile_token': 'synthetic-captcha',
        })

    def move(self, credential, *, revision=None, game_id=1, source='e2', target='e4', san='e4'):
        if revision is None:
            revision = self.execute('SELECT revision FROM games WHERE id=?', (game_id,))[0]['revision']
        return self.request('api/my_move_submit.php', {
            'token': credential, 'from': source, 'to': target, 'move': san,
            'game_id': game_id, 'expected_revision': revision,
        })

    def test_timestamp_only_submission_rejected(self):
        credential = self.token()
        before = self.snapshot()
        response = self.request('api/my_move_submit.php', {
            'token': credential, 'from': 'e2', 'to': 'e4', 'move': 'e4',
            'last_known_updated_at': self.execute('SELECT updated_at FROM games')[0]['updated_at'],
        })
        self.assertEqual(response[0], 400)
        self.assert_unchanged(before)

    def test_revision_and_game_id_are_authoritative(self):
        credential = self.token()
        before = self.snapshot()
        self.assertEqual(self.move(credential, game_id=999, revision=0)[0], 409)
        self.assertEqual(self.move(credential, revision=42)[0], 409)
        self.assert_unchanged(before)
        self.assertEqual(self.move(credential)[0], 200)
        self.assertEqual(self.execute('SELECT revision FROM games')[0]['revision'], 1)
        self.assertEqual(json.loads(self.request('api/state.php')[1])['revision'], 1)

    def test_sibling_capabilities_are_invalidated(self):
        first, sibling = self.token(), self.token()
        self.assertEqual(self.move(first)[0], 200)
        rows = self.execute('SELECT used,revoked_at FROM tokens WHERE token=?', (sibling,))
        self.assertTrue(rows[0]['revoked_at'] is not None)
        self.assertEqual(self.move(sibling, revision=1)[0], 403)

    def test_explicit_expiration_is_enforced(self):
        credential = self.token()
        self.execute("UPDATE tokens SET expires_at=datetime('now','-1 minute') WHERE token=?", (credential,))
        self.assertEqual(self.move(credential)[0], 410)

    def test_revoked_and_wrong_revision_links_fail_closed(self):
        credential = self.token()
        self.execute('UPDATE tokens SET game_revision=99 WHERE token=?', (credential,))
        before = self.snapshot()
        self.assertEqual(self.move(credential)[0], 403)
        self.assertEqual(self.request('api/state.php?token=' + credential)[0], 403)
        self.assert_unchanged(before)
        self.execute('UPDATE tokens SET game_revision=0,revoked_at=CURRENT_TIMESTAMP WHERE token=?', (credential,))
        self.assert_denied_resend(credential, 403)

    def test_one_active_game_and_unique_lock_constraints(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.execute("INSERT INTO games(host_color,visitor_color,turn_color,status,fen,pgn) "
                         "VALUES('black','white','white','active',?,'')", (START_FEN,))
        with self.assertRaises(sqlite3.IntegrityError):
            self.execute("INSERT INTO locks(game_id,name) VALUES(1,'fixture-lock')")
        for query in ("UPDATE games SET host_color=visitor_color", "UPDATE games SET revision=-1",
                      "UPDATE games SET revision=1.5", "UPDATE games SET status='unknown'"):
            with self.subTest(query=query), self.assertRaises(sqlite3.IntegrityError): self.execute(query)

    def test_concurrent_host_requests_commit_once(self):
        credential = self.token()
        (self.app / 'data/delay-status').write_text('yes')
        payload = {'token': credential, 'from': 'e2', 'to': 'e4', 'move': 'e4', 'game_id': 1, 'expected_revision': 0}
        with self.second_worker() as other, concurrent.futures.ThreadPoolExecutor(2) as pool:
            futures = [pool.submit(client.request, 'api/my_move_submit.php', payload) for client in (self, other)]
            responses = [f.result() for f in futures]
        self.assertEqual(sum(r[0] == 200 for r in responses), 1)
        self.assertEqual(self.execute('SELECT pgn,revision FROM games'), [{'pgn': '1. e4', 'revision': 1}])

    def test_two_workers_visitor_conflict_is_deterministic(self):
        self.execute("UPDATE games SET host_color='black',visitor_color='white' WHERE id=1")
        (self.app / 'data/delay-status').write_text('yes')
        payload = {'from': 'e2', 'to': 'e4', 'last_move_san': 'e4', 'game_id': 1,
                   'expected_revision': 0, 'turnstile_token': 'synthetic'}
        with self.second_worker() as other, concurrent.futures.ThreadPoolExecutor(2) as pool:
            responses = [f.result() for f in [pool.submit(client.request, 'api/visitor_move.php', payload)
                                             for client in (self, other)]]
        self.assertEqual(sorted(r[0] for r in responses), [200, 409])
        self.assertEqual(self.execute('SELECT pgn,revision FROM games'), [{'pgn': '1. e4', 'revision': 1}])
        self.assertEqual(len(self.mailbox()), 1)

    def test_same_timestamp_does_not_authorize_old_revision(self):
        self.execute("UPDATE games SET updated_at='2000-01-01 00:00:00'")
        self.execute("CREATE TRIGGER freeze_time AFTER UPDATE ON games WHEN NEW.updated_at<>'2000-01-01 00:00:00' "
                     "BEGIN UPDATE games SET updated_at='2000-01-01 00:00:00' WHERE id=NEW.id; END")
        try:
            first = self.token()
            self.assertEqual(self.move(first)[0], 200)
            self.assertEqual(self.visitor()[0], 200)
            current = self.execute('SELECT token FROM tokens WHERE used=0 AND revoked_at IS NULL')[0]['token']
            self.credentials.append(current)
            before = self.snapshot()
            self.assertEqual(self.move(current, revision=0, source='g1', target='f3', san='Nf3')[0], 409)
            self.assert_unchanged(before)
            self.assertEqual(self.execute('SELECT revision,updated_at FROM games'),
                             [{'revision': 2, 'updated_at': '2000-01-01 00:00:00'}])
        finally: self.execute('DROP TRIGGER freeze_time')

    def test_prior_turn_token_cannot_recover_or_move_after_cycle(self):
        first, old = self.token(), self.token()
        self.assertEqual(self.move(first)[0], 200)
        self.assertEqual(self.visitor()[0], 200)
        # Even clearing revocation cannot revive a capability for revision zero.
        self.execute('UPDATE tokens SET revoked_at=NULL WHERE token=?', (old,))
        before = self.snapshot()
        sent = len(self.mailbox())
        self.assertEqual(self.request('api/my_move_submit.php', {'action': 'resend', 'token': old})[0], 403)
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), sent)
        self.assertEqual(self.move(old, source='g1', target='f3', san='Nf3')[0], 403)

    def test_visitor_cannot_target_an_older_game(self):
        self.execute("UPDATE games SET status='finished'")
        self.execute("INSERT INTO games(id,host_color,visitor_color,turn_color,status,fen,pgn) "
                     "VALUES(2,'black','white','white','active',?,'')", (START_FEN,))
        before = self.snapshot()
        self.assertEqual(self.visitor(revision=0, source='e2', target='e4', san='e4')[0], 409)
        self.assert_unchanged(before)

    def test_database_busy_is_bounded_and_leaves_state_unchanged(self):
        credential = self.token()
        before = self.snapshot()
        connection = sqlite3.connect(self.db)
        try:
            connection.execute('BEGIN IMMEDIATE')
            response = self.move(credential, revision=0)
            self.assertEqual(response[0], 503)
            self.assertEqual(json.loads(response[1])['code'], 'database_busy')
            self.assertEqual(response[2].get('retry-after'), '1')
        finally: connection.rollback(); connection.close()
        self.assert_unchanged(before)

    def finish_fixture(self):
        self.execute("UPDATE games SET status='finished',revision=revision+1 WHERE id=1")
        (self.app / 'data/status.json').write_text(json.dumps({'over': True, 'reason': 'checkmate', 'winner': 'w'}))
        return self.token(purpose='next_game')

    def test_transition_replay_returns_same_successor_without_secret(self):
        credential = self.finish_fixture()
        payload = {'token': credential}
        first = self.request('api/host_next_game.php', payload)
        self.assertEqual(first[0], 200)
        before = self.snapshot()
        second = self.request('api/host_next_game.php', payload)
        self.assertEqual(second[0], 200)
        self.assertEqual(json.loads(first[1])['new_game_id'], json.loads(second[1])['new_game_id'])
        self.assert_unchanged(before)
        self.assertTrue('host_token' not in json.loads(second[1]))
        self.assertEqual(len(self.execute("SELECT id FROM games WHERE status='active'")), 1)
        self.assertEqual(len(self.execute('SELECT * FROM game_transitions')), 1)
        self.assertEqual(len(self.execute('SELECT * FROM game_results')), 1)

    def test_legacy_used_move_token_cannot_start_next_game(self):
        self.finish_fixture()
        credential = self.token(used=1)
        before = self.snapshot()
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 403)
        self.assert_unchanged(before)

    def test_next_game_capability_cannot_make_a_move(self):
        credential = self.finish_fixture()
        before = self.snapshot()
        self.assertEqual(self.move(credential)[0], 403)
        self.assert_unchanged(before)

    def test_expired_completion_link_recovers_same_purpose_only_by_email(self):
        credential = self.finish_fixture()
        self.execute("UPDATE tokens SET created_at=datetime('now','-1 day'),expires_at=datetime('now','-1 day','+2 hours') WHERE token=?", (credential,))
        response = self.request('api/my_move_submit.php', {'action': 'resend', 'token': credential})
        self.assertEqual(response[0], 200)
        self.assert_private(response)
        replacement = self.execute('SELECT token,purpose,game_revision FROM tokens WHERE token<>?', (credential,))[0]
        self.credentials.append(replacement['token'])
        self.assertEqual(replacement['purpose'], 'next_game')
        self.assertEqual(replacement['game_revision'], 1)
        self.assertTrue(replacement['token'] in self.mailbox()[0]['body'])
        self.assertTrue(replacement['token'] not in response[1])
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 410)

    def test_host_terminal_move_returns_completion_authority_only_to_host(self):
        fen = 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2'
        mate = 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3'
        self.execute("UPDATE games SET host_color='black',visitor_color='white',turn_color='black',fen=?,pgn='1. f3 e5 2. g4'", (fen,))
        credential = self.token()
        (self.app / 'data/status-by-fen.json').write_text(json.dumps({mate: {'over': True, 'reason': 'checkmate', 'winner': 'b'}}))
        response = self.move(credential, source='d8', target='h4', san='Qh4#')
        self.assertEqual(response[0], 200)
        completion = json.loads(response[1])['next_game_token']
        self.credentials.append(completion)
        self.assertTrue(completion != credential)
        self.assertEqual(self.execute('SELECT purpose FROM tokens WHERE token=?', (completion,)), [{'purpose': 'next_game'}])
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 403)
        self.assertEqual(self.request('api/host_next_game.php', {'token': completion})[0], 200)

    def test_result_write_failure_rolls_back_terminal_move(self):
        fen = 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2'
        mate = 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3'
        self.execute("UPDATE games SET host_color='black',visitor_color='white',turn_color='black',fen=?", (fen,))
        credential = self.token()
        (self.app / 'data/status-by-fen.json').write_text(json.dumps({mate: {'over': True, 'reason': 'checkmate', 'winner': 'b'}}))
        self.execute("CREATE TRIGGER reject_result BEFORE INSERT ON game_results BEGIN SELECT RAISE(ABORT,'synthetic failure'); END")
        before = self.snapshot()
        try:
            self.assertEqual(self.move(credential, source='d8', target='h4', san='Qh4#')[0], 500)
            self.assert_unchanged(before)
        finally: self.execute('DROP TRIGGER reject_result')

    def test_transition_rollback_includes_result_and_consumption(self):
        credential = self.finish_fixture()
        self.execute("CREATE TRIGGER reject_successor BEFORE INSERT ON games BEGIN SELECT RAISE(ABORT,'synthetic failure'); END")
        before = self.snapshot()
        try:
            response = self.request('api/host_next_game.php', {'token': credential})
            self.assertEqual(response[0], 500)
            self.assert_unchanged(before)
            self.assertEqual(len(self.mailbox()), 0)
        finally: self.execute('DROP TRIGGER reject_successor')
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 200)

    def test_baseline_cutoff_prevents_recounting_imported_game(self):
        self.execute('UPDATE score_baseline SET last_counted_game_id=1')
        credential = self.finish_fixture()
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 200)
        score = json.loads(self.request('api/state.php')[1])['score']
        self.assertEqual((score['host_wins'], score['world_wins'], score['draws']), (3, 2, 1))
        self.assertEqual(self.execute('SELECT counted FROM game_results'), [{'counted': 0}])

    def test_two_workers_transition_once_and_email_once(self):
        self.execute("UPDATE games SET host_color='black',visitor_color='white'")
        credential = self.finish_fixture()
        (self.app / 'data/delay-status').write_text('yes')
        with self.second_worker() as other, concurrent.futures.ThreadPoolExecutor(2) as pool:
            responses = [f.result() for f in [pool.submit(client.request, 'api/host_next_game.php', {'token': credential})
                                             for client in (self, other)]]
        self.assertEqual([r[0] for r in responses], [200, 200])
        self.assertEqual(len(set(json.loads(r[1])['new_game_id'] for r in responses)), 1)
        self.assertEqual(len(self.execute('SELECT * FROM game_results')), 1)
        self.assertEqual(len(self.mailbox()), 1)
        score = json.loads(self.request('api/state.php')[1])['score']
        self.assertEqual(score['world_wins'], 3)

    def test_unknown_result_blocks_transition_without_mutation(self):
        credential = self.finish_fixture()
        (self.app / 'data/status.json').write_text('{"over":false}')
        before = self.snapshot()
        self.assertEqual(self.request('api/host_next_game.php', {'token': credential})[0], 503)
        self.assert_unchanged(before)

    def test_visitor_terminal_move_gets_separate_completion_link(self):
        fen = 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2'
        mate = 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3'
        self.execute("UPDATE games SET fen=?,pgn='1. f3 e5 2. g4',turn_color='black'", (fen,))
        (self.app / 'data/status-by-fen.json').write_text(json.dumps({mate: {'over': True, 'reason': 'checkmate', 'winner': 'b'}}))
        self.assertEqual(self.visitor(source='d8', target='h4', san='Qh4#')[0], 200)
        self.assertEqual(self.execute('SELECT status,revision FROM games'), [{'status': 'finished', 'revision': 1}])
        self.assertEqual(self.execute('SELECT purpose FROM tokens'), [{'purpose': 'next_game'}])
        self.assertEqual(self.execute('SELECT result FROM game_results'), [{'result': 'world'}])
        self.assertEqual(len(self.mailbox()), 1)

    def legacy(self, *, duplicate_active=False, duplicate_lock=False):
        path = Path(self.temporary.name) / 'legacy.sqlite'
        path.unlink(missing_ok=True)
        with sqlite3.connect(path) as db:
            db.executescript(LEGACY_SCHEMA)
            db.execute("INSERT INTO games(id,host_color,visitor_color,turn_color,fen,pgn,updated_at) "
                       "VALUES(9,'white','black','white',?,'1. e4 e5','2026-01-01 12:00:00')", (START_FEN,))
            token = secrets.token_urlsafe(32)
            self.credentials.append(token)
            db.execute("INSERT INTO tokens(game_id,token,purpose) VALUES(9,?,'your_move')", (token,))
            db.execute("INSERT INTO locks(game_id,name) VALUES(9,'visitor_turn')")
            if duplicate_active:
                db.execute("INSERT INTO games(host_color,visitor_color,turn_color,fen,pgn) VALUES('black','white','white',?,'')", (START_FEN,))
            if duplicate_lock: db.execute("INSERT INTO locks(game_id,name) VALUES(9,'visitor_turn')")
        score = Path(self.temporary.name) / 'legacy-score.json'
        score.write_text(json.dumps(dict(host_wins=7, world_wins=4, draws=2, last_counted_game_id=8,
                                        last_result='draw', updated_at='2026-01-01 12:00:00')))
        return path, score

    def migration(self, path, score, *, apply=False):
        return subprocess.run(self.php_options + [str(self.app / 'scripts/migrate_phase2.php'),
            '--database=' + str(path), '--score-file=' + str(score)] + (['--apply'] if apply else []),
            capture_output=True, text=True)

    def test_migration_preflight_is_read_only_and_apply_preserves_data(self):
        path, score = self.legacy()
        before = hashlib.sha256(path.read_bytes()).digest()
        self.assertEqual(self.migration(path, score).returncode, 0)
        self.assertTrue(hashlib.sha256(path.read_bytes()).digest() == before)
        original_score = score.read_bytes()
        with sqlite3.connect(path) as db: original = db.execute('SELECT * FROM games').fetchall()
        result = self.migration(path, score, apply=True)
        self.assertEqual(result.returncode, 0, 'Migration failed')
        with sqlite3.connect(path) as db:
            self.assertEqual(db.execute('SELECT id,host_color,visitor_color,turn_color,status,fen,pgn,last_move_san,updated_at FROM games').fetchall(), original)
            self.assertEqual(db.execute('SELECT host_wins,world_wins,draws,last_counted_game_id FROM score_baseline').fetchone(), (7, 4, 2, 8))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM tokens WHERE revoked_at IS NOT NULL').fetchone()[0], 1)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM locks').fetchone()[0], 1)
        self.assertEqual(score.read_bytes(), original_score)
        before = hashlib.sha256(path.read_bytes()).digest()
        self.assertEqual(self.migration(path, score, apply=True).returncode, 0)
        self.assertTrue(hashlib.sha256(path.read_bytes()).digest() == before)

    def test_migration_refuses_conflicts_without_partial_changes(self):
        for fields in ({'duplicate_active': True}, {'duplicate_lock': True}):
            with self.subTest(kind=next(iter(fields))):
                path, score = self.legacy(**fields)
                before = path.read_bytes()
                self.assertNotEqual(self.migration(path, score, apply=True).returncode, 0)
                self.assertTrue(path.read_bytes() == before, 'Refused migration changed database')

    def test_migration_refuses_inconsistent_current_game_or_turn(self):
        for mode in ('old-active', 'turn-mismatch'):
            with self.subTest(mode=mode):
                path, score = self.legacy()
                with sqlite3.connect(path) as db:
                    if mode == 'old-active':
                        db.execute("INSERT INTO games(host_color,visitor_color,turn_color,status,fen,pgn) VALUES('black','white','white','finished',?,'')", (START_FEN,))
                    else: db.execute("UPDATE games SET turn_color='black'")
                before = path.read_bytes()
                self.assertNotEqual(self.migration(path, score, apply=True).returncode, 0)
                self.assertTrue(path.read_bytes() == before)

    def test_migration_rejects_missing_or_malformed_score(self):
        path, score = self.legacy()
        before = path.read_bytes()
        score.write_text('not JSON')
        self.assertNotEqual(self.migration(path, score, apply=True).returncode, 0)
        score.unlink()
        self.assertNotEqual(self.migration(path, score, apply=True).returncode, 0)
        self.assertTrue(path.read_bytes() == before)

    def test_migration_does_not_reset_already_migrated_totals(self):
        path, score = self.legacy()
        self.assertEqual(self.migration(path, score, apply=True).returncode, 0)
        score.write_text('{"host_wins":999,"world_wins":999,"draws":999,"last_counted_game_id":0}')
        self.assertEqual(self.migration(path, score, apply=True).returncode, 0)
        with sqlite3.connect(path) as db:
            self.assertEqual(db.execute('SELECT host_wins,world_wins,draws FROM score_baseline').fetchone(), (7, 4, 2))

    def test_runtime_refuses_unmigrated_database_without_writes(self):
        path, score = self.legacy()
        before = path.read_bytes()
        code = "define('DB_PATH'," + json.dumps(str(path)) + ");require __DIR__.'/db.php';try{get_db();exit(1);}catch(Throwable $e){echo 'refused';}"
        self.assertEqual(self.php_script(code).stdout, 'refused')
        self.assertTrue(path.read_bytes() == before)

    def test_existing_database_bootstrap_is_refused(self):
        before = self.snapshot()
        result = subprocess.run(self.php_options + [str(self.app / 'init_db.php'), '--new'],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assert_unchanged(before)

    def test_special_characters_in_database_path_open_existing_file(self):
        path = Path(self.temporary.name) / 'space ? hash# percent%.sqlite'
        path.write_bytes(self.db.read_bytes())
        code = "define('DB_PATH'," + json.dumps(str(path)) + ");require __DIR__.'/db.php';echo get_db()->query('SELECT COUNT(*) FROM games')->fetchColumn();"
        result = self.php_script(code)
        self.assertEqual(result.stdout, '1')
        self.assertEqual(result.stderr, '')

    def test_missing_database_never_created_by_runtime_or_migration(self):
        path = Path(self.temporary.name) / 'missing' / 'chess.sqlite'
        code = "define('DB_PATH'," + json.dumps(str(path)) + ");require __DIR__.'/db.php';try{get_db();exit(1);}catch(Throwable $e){echo 'refused';}"
        result = self.php_script(code)
        self.assertEqual(result.stdout, 'refused')
        self.assertFalse(path.parent.exists())
        self.assertNotEqual(self.migration(path, path).returncode, 0)
        self.assertFalse(path.exists())

    def test_path_resolution_absolute_relative_windows_and_legacy_guard(self):
        for value in ('data/test.sqlite', str(self.db), 'C:\\private\\chess.sqlite', '//server/share/chess.sqlite'):
            code = "define('DB_PATH'," + json.dumps(value) + ");require __DIR__.'/db.php';echo json_encode(resolve_db_path_info()['anchored']);"
            result = self.php_script(code)
            expected = str(self.app / value) if value == 'data/test.sqlite' else value
            self.assertEqual(json.loads(result.stdout), expected)
            self.assertEqual(result.stderr, '')
        path = Path(self.temporary.name) / 'verified.sqlite'
        legacy = self.app / str(path).lstrip('/')
        legacy.parent.mkdir(parents=True)
        legacy.write_bytes(self.db.read_bytes())
        code = "define('DB_PATH'," + json.dumps(str(path)) + ");require __DIR__.'/db.php';try{get_db();exit(1);}catch(Throwable $e){echo 'refused';}"
        self.assertEqual(self.php_script(code).stdout, 'refused')
        self.assertFalse(path.exists())
        self.assertTrue(legacy.is_file())

    def test_private_operator_link_preserves_game_and_score(self):
        output = Path(self.temporary.name) / 'private-link.txt'
        output.unlink(missing_ok=True)
        game = self.execute('SELECT * FROM games')
        result = subprocess.run(self.php_options + [str(self.app / 'scripts/host_link.php'),
            '--database=' + str(self.db), '--output=' + str(output)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(output.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.execute('SELECT * FROM games'), game)
        self.assertEqual((self.app / 'data/score.json').read_bytes(), self.score)
        credential = self.execute('SELECT token FROM tokens WHERE revoked_at IS NULL')[0]['token']
        self.credentials.append(credential)
        self.assertTrue(credential in output.read_text())
        self.assertTrue(credential not in result.stdout + result.stderr)
        self.assertEqual(len(self.mailbox()), 0)

    def test_operator_output_failure_rolls_back_token_revocation(self):
        self.token()
        output = Path(self.temporary.name) / 'already-exists.txt'
        output.write_text('private sentinel')
        before = self.snapshot()
        result = subprocess.run(self.php_options + [str(self.app / 'scripts/host_link.php'),
            '--database=' + str(self.db), '--output=' + str(output)], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assert_unchanged(before)
        self.assertEqual(output.read_text(), 'private sentinel')

    def test_operator_scripts_are_not_http_actions(self):
        before = self.snapshot()
        for path in ('init_db.php', 'scripts/migrate_phase2.php', 'scripts/host_link.php'):
            with self.subTest(path=path): self.assertEqual(self.request(path)[0], 404)
        self.assert_unchanged(before)


if __name__ == '__main__':
    unittest.main(verbosity=2)
