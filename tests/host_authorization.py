#!/usr/bin/env python3
"""Phase 1 HTTP regressions. Only a private temporary application is served.

Run: PHP_BIN=php python3 tests/host_authorization.py
Requires Python's standard library, PHP 8+ with pdo_sqlite, and Node.js.
Native mail/shell execution are disabled; chess validation uses the real local engine.
"""

import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
START_FEN = "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
AFTER_E4 = "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


class HostAuthorization(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix="chess-host-auth-")
        cls.app = Path(cls.temporary.name) / "app"
        cls.app.mkdir()
        cls.db = cls.app / "data/test.sqlite"
        cls.php = os.environ.get("PHP_BIN", "php")
        cls.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect()
        )
        try:
            # Copy tracked source only; never copy local configuration/runtime data.
            tracked = subprocess.check_output(
                ["git", "ls-files", "-z"], cwd=ROOT
            ).decode().split("\0")
            # Include runtime helpers/CLI tools from an uncommitted implementation.
            for directory in ('lib', 'scripts'):
                for source in (ROOT / directory).iterdir():
                    if source.suffix not in ('.php', '.js'): continue
                    name = str(source.relative_to(ROOT))
                    if name not in tracked: tracked.append(name)
            for name in tracked:
                if not name or name == "config.local.php" or name.startswith("data/"):
                    continue
                source, target = ROOT / name, cls.app / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, target)
            (cls.app / "data").mkdir()
            (cls.app / "config.local.php").write_text("""<?php
if (!defined('DB_PATH')) define('DB_PATH', 'data/test.sqlite');
define('YOUR_EMAIL', 'host@example.invalid');
define('MAIL_FROM', 'chess@example.invalid');
define('BASE_URL', 'https://example.invalid/chess');
define('CANONICAL_CHESS_BASE', 'https://example.invalid/chess');
define('TURNSTILE_SITE_KEY', 'synthetic-site');
define('TURNSTILE_SECRET_KEY', 'synthetic-secret');
define('ADMIN_RESET_KEY', 'synthetic-admin-not-used');
define('CHESS_NODE_BINARY', is_file(__DIR__ . '/data/node-runtime')
    ? trim(file_get_contents(__DIR__ . '/data/node-runtime')) : 'node');
""")
            cls.bootstrap = cls.app / "test-bootstrap.php"
            cls.bootstrap.write_text("""<?php
// PHP 8 permits defining replacements for disabled native functions.
function mail($to, $subject, $message, $headers = '', $params = '') {
    $mode = trim(file_get_contents(__DIR__ . '/data/mail-mode'));
    // A private simulated mailbox, never a real transport or test-output log.
    file_put_contents(__DIR__ . '/data/mailbox.jsonl', json_encode([
        'to' => $to, 'subject' => $subject, 'body' => $message, 'headers' => $headers,
    ]) . "\\n", FILE_APPEND);
    if ($mode === 'throw') throw new RuntimeException('Synthetic transport failure');
    return $mode !== 'fail';
}
function shell_exec($command) {
    throw new RuntimeException('Unexpected legacy shell execution');
}
// No request made by this suite may reach an external service.
foreach (['http', 'https'] as $scheme) {
    if (in_array($scheme, stream_get_wrappers(), true)) stream_wrapper_unregister($scheme);
}
class SyntheticTurnstile {
    public $context; private $position = 0;
    function stream_open($path, $mode, $options, &$opened) {
        return $path === 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    }
    function stream_read($count) { $body = '{"success":true}'; $part = substr($body, $this->position, $count); $this->position += strlen($part); return $part; }
    function stream_eof() { return $this->position >= 16; }
    function stream_stat() { return []; }
}
stream_wrapper_register('https', 'SyntheticTurnstile');
""")
            (cls.app / "data/mail-mode").write_text("accept")
            cls.php_options = [
                cls.php, "-d", "disable_functions=mail,shell_exec",
                "-d", "display_errors=0", "-d", "log_errors=1",
                "-d", "error_log=" + str(cls.app / "data/application.log"),
                "-d", "auto_prepend_file=" + str(cls.bootstrap),
            ]
            subprocess.run(cls.php_options + [str(cls.app / "init_db.php"), "--new"],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            with socket.socket() as probe:
                probe.bind(("127.0.0.1", 0))
                port = probe.getsockname()[1]
            cls.base = f"http://127.0.0.1:{port}/"
            environment = dict(os.environ)
            environment.pop("PHP_CLI_SERVER_WORKERS", None)
            # Discard development-server access logs: they contain raw request URLs.
            cls.server = subprocess.Popen(
                cls.php_options + ["-S", f"127.0.0.1:{port}", "-t", str(cls.app)],
                env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            for _attempt in range(100):
                if cls.server.poll() is not None:
                    raise RuntimeError("Isolated PHP server exited before startup")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("Isolated PHP server did not start")
        except Exception:
            cls.tearDownClass()
            raise

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, "server"):
            cls.server.terminate()
            try:
                cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.server.kill()
                cls.server.wait()
        cls.temporary.cleanup()

    def execute(self, query, parameters=()):
        with sqlite3.connect(self.db) as connection:
            connection.row_factory = sqlite3.Row
            return [dict(row) for row in connection.execute(query, parameters).fetchall()]

    def setUp(self):
        self.execute("DELETE FROM game_transitions")
        self.execute("DELETE FROM game_results")
        self.execute("DELETE FROM tokens")
        self.execute("DELETE FROM locks")
        self.execute("DELETE FROM games")
        self.execute("INSERT INTO games (id,host_color,visitor_color,turn_color,status,fen,pgn) "
                     "VALUES (1,'white','black','white','active',?,'')", (START_FEN,))
        self.execute("INSERT INTO locks (game_id,name) VALUES (1,'fixture-lock')")
        for name in ("resend_throttle.json", "mailbox.jsonl", "application.log", "node-runtime"):
            (self.app / "data" / name).unlink(missing_ok=True)
        (self.app / "data/mail-mode").write_text("accept")
        self.score = b'{"host_wins":3,"world_wins":2,"draws":1,"last_counted_game_id":0}'
        (self.app / "data/score.json").write_bytes(self.score)
        self.execute("UPDATE score_baseline SET host_wins=3,world_wins=2,draws=1,last_counted_game_id=0")
        self.credentials = []

    def token(self, *, game_id=1, age="now", used=0, used_at=None, purpose="your_move"):
        credential = secrets.token_urlsafe(32)
        self.credentials.append(credential)
        self.execute("INSERT INTO tokens (game_id,token,purpose,used,created_at,used_at,game_revision,expires_at) "
                     "VALUES (?,?,?,?,datetime(?),?,(SELECT revision FROM games WHERE id=?),datetime(?,'+2 hours'))",
                     (game_id, credential, purpose, used, age, used_at, game_id, age))
        return credential

    def snapshot(self):
        # Assertions compare digests so a failure cannot print bearer credentials.
        rows = {table: self.execute(f"SELECT * FROM {table} ORDER BY 1")
                for table in ("games", "tokens", "locks", "game_results", "game_transitions", "score_baseline")}
        return hashlib.sha256(json.dumps(rows, sort_keys=True).encode()).hexdigest()

    def request(self, path, payload=None, method=None):
        request = urllib.request.Request(
            self.base + path,
            data=None if payload is None else json.dumps(payload).encode(),
            headers={"Content-Type": "application/json"}, method=method,
        )
        try:
            response = self.opener.open(request, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode()
            headers = {key.lower(): value for key, value in response.headers.items()}
            return response.status, body, headers

    def mailbox(self):
        path = self.app / "data/mailbox.jsonl"
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def assert_private(self, response):
        _status, body, headers = response
        self.assertIn("no-store", headers.get("cache-control", ""))
        self.assertEqual(headers.get("referrer-policy"), "no-referrer")
        self.assertTrue("location" not in headers, "Unexpected redirect")
        for credential in self.credentials:
            self.assertTrue(credential not in body, "Credential disclosed in recovery response")

    def assert_unchanged(self, before):
        self.assertEqual(before, self.snapshot(), "Game/token/lock rows changed")
        self.assertEqual((self.app / "data/score.json").read_bytes(), self.score)

    def assert_denied_resend(self, credential, expected):
        before = self.snapshot()
        response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
        self.assertEqual(response[0], expected)
        self.assert_private(response)
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)
        self.assertFalse((self.app / "data/resend_throttle.json").exists())

    def test_invalid_get_and_head_never_grant_or_mutate(self):
        existing = self.token()
        cases = {
            "missing": "", "invalid": "?token=unknown", "empty": "?token=",
            "expired": "?token=" + self.token(age="now", used=0),
            "used": "?token=" + self.token(used=1),
        }
        self.execute("UPDATE tokens SET created_at=datetime('now','-3 hours'),expires_at=datetime('now','-1 hour') WHERE token=?",
                     (cases["expired"].split("=", 1)[1],))
        for label, query in cases.items():
            for method in ("GET", "HEAD"):
                with self.subTest(kind=label, method=method):
                    before = self.snapshot()
                    status, body, headers = self.request("my_move.php" + query, method=method)
                    self.assertEqual(status, 200)
                    self.assertIn("no-store", headers.get("cache-control", ""))
                    self.assertEqual(headers.get("referrer-policy"), "no-referrer")
                    self.assertTrue("location" not in headers, "Credential redirect issued")
                    self.assertTrue(existing not in body, "Existing host credential disclosed")
                    self.assertTrue("window.INITIAL_TOKEN_VALID = true" not in body)
                    self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_valid_get_head_and_alias_are_non_consuming(self):
        credential = self.token()
        for key in ("token", "t"):
            for method in ("GET", "HEAD"):
                with self.subTest(key=key, method=method):
                    before = self.snapshot()
                    response = self.request("my_move.php?" + key + "=" + credential + "&fresh=1", method=method)
                    self.assertEqual(response[0], 200)
                    self.assertIn("no-store", response[2].get("cache-control", ""))
                    self.assertEqual(response[2].get("referrer-policy"), "no-referrer")
                    self.assertTrue("location" not in response[2])
                    if method == "GET":
                        self.assertTrue("window.INITIAL_TOKEN_VALID = true" in response[1])
                        self.assertTrue("Issued a fresh link" not in response[1])
                    self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_resend_missing_denied(self):
        self.token()
        self.assert_denied_resend("", 401)

    def test_resend_invalid_denied(self):
        self.token()
        self.assert_denied_resend("unknown", 403)

    def test_resend_without_token_field_denied(self):
        self.token()
        before = self.snapshot()
        response = self.request("api/my_move_submit.php", {"action": "resend"})
        self.assertEqual(response[0], 401)
        self.assert_private(response)
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_resend_get_and_head_never_issue_credentials(self):
        credential = self.token()
        for method in ("GET", "HEAD"):
            with self.subTest(method=method):
                before = self.snapshot()
                response = self.request("api/my_move_submit.php?action=resend&token=" + credential,
                                        method=method)
                self.assertEqual(response[0], 405)
                self.assert_private(response)
                self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_resend_used_or_revoked_denied(self):
        for label, fields in (("used", {"used": 1}), ("used_at", {"used_at": "2026-01-01 00:00:00"}),
                              ("expired-used", {"used": 1, "age": "2000-01-01"}),
                              ("revoked-purpose", {"purpose": "revoked"})):
            with self.subTest(kind=label):
                self.assert_denied_resend(self.token(**fields), 403)

    def test_resend_expired_beyond_window_denied(self):
        self.assert_denied_resend(self.token(age="2000-01-01"), 410)

    def test_resend_at_seven_day_boundary_denied(self):
        credential = self.token()
        self.execute("UPDATE tokens SET created_at=datetime('now','-7 days'),expires_at=datetime('now','-7 days','+2 hours') WHERE token=?", (credential,))
        self.assert_denied_resend(credential, 410)

    def test_resend_unknown_or_future_issuance_denied(self):
        for value in (None, "not-a-date", "2999-01-01 00:00:00"):
            with self.subTest(kind="invalid-issuance" if value != None else "missing-issuance"):
                credential = self.token()
                self.execute("UPDATE tokens SET created_at=? WHERE token=?", (value, credential))
                self.assert_denied_resend(credential, 403)

    def test_resend_finished_game_denied_without_fallback(self):
        credential = self.token()
        self.execute("UPDATE games SET status='finished' WHERE id=1")
        self.assert_denied_resend(credential, 403)

    def test_resend_older_game_denied_without_fallback(self):
        credential = self.token()
        self.execute("UPDATE games SET status='finished' WHERE id=1")
        self.execute("INSERT INTO games (id,host_color,visitor_color,turn_color,status,fen,pgn) "
                     "VALUES (2,'white','black','white','active',?,'')", (START_FEN,))
        self.assert_denied_resend(credential, 403)

    def test_resend_visitor_turn_denied(self):
        credential = self.token()
        self.execute("UPDATE games SET turn_color='black' WHERE id=1")
        self.assert_denied_resend(credential, 403)

    def test_resend_deleted_game_denied(self):
        credential = self.token()
        self.execute("DELETE FROM games")  # This connection intentionally permits an orphan fixture.
        self.assert_denied_resend(credential, 403)

    def test_valid_resend_is_email_only(self):
        credential = self.token()
        games = self.execute("SELECT * FROM games")
        response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
        self.assertEqual(response[0], 200)
        self.assert_private(response)
        self.assertEqual(self.execute("SELECT * FROM games"), games)
        self.assertEqual((self.app / "data/score.json").read_bytes(), self.score)
        messages = self.mailbox()
        self.assertEqual(len(messages), 1)
        self.assertEqual(messages[0]["to"], "host@example.invalid")
        self.assertTrue("Game ID: 1" in messages[0]["body"])
        self.assertTrue("my_move.php?token=" in messages[0]["body"])
        for row in self.execute("SELECT token FROM tokens"):
            self.assertTrue(row["token"] not in response[1], "Replacement credential disclosed")

    def test_resend_ignores_request_recipient_and_game(self):
        credential = self.token()
        response = self.request("api/my_move_submit.php", {
            "action": "resend", "token": credential,
            "email": "attacker@example.invalid", "game_id": 999,
        })
        self.assertEqual(response[0], 200)
        self.assert_private(response)
        message = self.mailbox()[0]
        self.assertEqual(message["to"], "host@example.invalid")
        self.assertTrue("Game ID: 1" in message["body"])
        self.assertTrue(all(row["game_id"] == 1 for row in self.execute("SELECT game_id FROM tokens")))

    def test_expired_recovery_is_email_only_and_does_not_authorize_old_token(self):
        credential = self.token()
        self.execute("UPDATE tokens SET created_at=datetime('now','-6 days'),expires_at=datetime('now','-6 days','+2 hours') WHERE token=?", (credential,))
        response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
        self.assertEqual(response[0], 200)
        self.assert_private(response)
        self.assertEqual(len(self.mailbox()), 1)
        self.assertEqual(self.request("api/state.php?token=" + credential)[0], 410)
        self.assertEqual(self.submit(credential)[0], 410)
        replacement = self.execute("SELECT token FROM tokens WHERE token<>?", (credential,))
        self.assertEqual(len(replacement), 1)
        new_token = replacement[0]["token"]
        self.credentials.append(new_token)
        self.assertTrue(new_token in self.mailbox()[0]["body"], "Replacement absent from simulated email")
        self.assertTrue(new_token not in response[1], "Replacement disclosed over HTTP")
        page = self.request("my_move.php?token=" + new_token)
        self.assertTrue("window.INITIAL_TOKEN_VALID = true" in page[1])
        self.assertEqual(self.submit(new_token)[0], 200)

    def test_cooldown_blocks_repeat_without_token_mutation(self):
        credential = self.token()
        self.assertEqual(self.request("api/my_move_submit.php", {"action": "resend", "token": credential})[0], 200)
        before = self.snapshot()
        response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
        self.assertEqual(response[0], 429)
        self.assert_private(response)
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 1)

    def test_cooldown_storage_failure_fails_closed(self):
        credential = self.token()
        path = self.app / "data/resend_throttle.json"
        path.mkdir()
        try:
            before = self.snapshot()
            response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
            self.assertEqual(response[0], 503)
            self.assert_private(response)
            self.assert_unchanged(before)
            self.assertEqual(len(self.mailbox()), 0)
        finally:
            path.rmdir()

    def test_corrupt_cooldown_fails_closed(self):
        credential = self.token()
        (self.app / "data/resend_throttle.json").write_text("not-json")
        before = self.snapshot()
        response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
        self.assertEqual(response[0], 503)
        self.assert_private(response)
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_mail_false_and_exception_never_disclose_replacement(self):
        credential = self.token()
        for mode in ("fail", "throw"):
            with self.subTest(mode=mode):
                (self.app / "data/resend_throttle.json").unlink(missing_ok=True)
                (self.app / "data/mail-mode").write_text(mode)
                response = self.request("api/my_move_submit.php", {"action": "resend", "token": credential})
                self.assertEqual(response[0], 500)
                for row in self.execute("SELECT token FROM tokens"):
                    self.credentials.append(row["token"])
                self.assert_private(response)
        self.assertEqual(self.execute("SELECT fen FROM games")[0]["fen"], START_FEN)
        self.assertEqual((self.app / "data/score.json").read_bytes(), self.score)

    def submit(self, credential, query=""):
        timestamp = self.execute("SELECT updated_at FROM games WHERE id=1")[0]["updated_at"]
        return self.request("api/my_move_submit.php" + query, {
            "token": credential, "from": "e2", "to": "e4", "move": "e4",
            "game_id": 1, "expected_revision": self.execute("SELECT revision FROM games WHERE id=1")[0]["revision"],
        })

    def test_valid_state_and_ordinary_host_move_still_work(self):
        credential = self.token()
        before = self.snapshot()
        response = self.request("api/state.php?token=" + credential)
        self.assertEqual(response[0], 200)
        self.assertEqual(json.loads(response[1])["fen"], START_FEN)
        self.assert_unchanged(before)
        self.assertEqual(self.submit(credential)[0], 200)
        game = self.execute("SELECT * FROM games WHERE id=1")[0]
        self.assertEqual(game["fen"], AFTER_E4)
        self.assertEqual(game["pgn"], "1. e4")
        self.assertEqual(game["turn_color"], "black")
        self.assertEqual(self.execute("SELECT used FROM tokens")[0]["used"], 1)
        self.assertEqual(len(self.execute("SELECT * FROM locks")), 0)
        self.assertEqual(self.submit(credential)[0], 403)
        self.assertEqual(len(self.mailbox()), 0)

    def test_invalid_direct_submissions_leave_tokens_unchanged(self):
        cases = [("missing", "", 401), ("invalid", "unknown", 403),
                 ("used", self.token(used=1), 403),
                 ("used_at", self.token(used_at="2026-01-01 00:00:00"), 403)]
        expired = self.token()
        self.execute("UPDATE tokens SET created_at=datetime('now','-1 day'),expires_at=datetime('now','-1 day','+2 hours') WHERE token=?", (expired,))
        cases.append(("expired", expired, 410))
        for label, credential, expected in cases:
            with self.subTest(kind=label):
                before = self.snapshot()
                response = self.submit(credential)
                self.assertEqual(response[0], expected)
                self.assert_private(response)
                self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 0)

    def test_expired_get_cannot_activate_board_or_mutate(self):
        credential = self.token()
        self.execute("UPDATE tokens SET created_at=datetime('now','-1 day'),expires_at=datetime('now','-1 day','+2 hours') WHERE token=?", (credential,))
        before = self.snapshot()
        response = self.request("my_move.php?token=" + credential)
        self.assertEqual(response[0], 200)
        self.assertTrue("window.INITIAL_TOKEN_VALID = false" in response[1])
        self.assertTrue("location" not in response[2])
        self.assert_unchanged(before)

    def test_state_and_submit_application_logs_drop_entire_query(self):
        credential = self.token()
        unknown = secrets.token_urlsafe(32)
        self.credentials.append(unknown)
        for value in (credential, unknown, ""):
            self.request("api/state.php?token=" + value + "&t=" + unknown + "&extra=" + unknown)
        self.execute("UPDATE tokens SET created_at=datetime('now','-1 day'),expires_at=datetime('now','-1 day','+2 hours') WHERE token=?", (credential,))
        self.request("api/state.php?%74oken=" + credential)
        self.execute("UPDATE tokens SET created_at=CURRENT_TIMESTAMP,expires_at=datetime('now','+2 hours') WHERE token=?", (credential,))
        self.submit(credential, "?token=" + credential + "&t=" + unknown)
        self.submit(unknown, "?token=" + unknown)
        logs = (self.app / "data/application.log").read_text()
        for secret in self.credentials:
            self.assertTrue(secret not in logs, "Full credential leaked in application logs")
        self.assertIn("path=/api/state.php", logs)
        self.assertIn("path=/api/my_move_submit.php", logs)
        self.assertTrue("?token=" not in logs, "Query string remained in application logs")


if __name__ == "__main__":
    unittest.main(verbosity=2)
