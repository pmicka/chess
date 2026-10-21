#!/usr/bin/env python3
"""Phase 3 HTTP regressions using real bundled chess.js, private SQLite and stub mail.

Run: PHP_BIN=php python3 tests/chess_legality.py
Includes the Phase 1 and Phase 2 suites. Node.js must be on PATH.
"""

import json
import subprocess
import time
import unittest

import state_integrity as integrity
from host_authorization import START_FEN


class ChessLegality(integrity.StateIntegrity):
    def position(self, fen, *, pgn=None, actor='host'):
        turn = 'white' if fen.split()[1] == 'w' else 'black'
        other = 'black' if turn == 'white' else 'white'
        host, visitor = (turn, other) if actor == 'host' else (other, turn)
        if pgn is None:
            pgn = f'[SetUp "1"]\n[FEN "{fen}"]\n\n*'
        self.execute('UPDATE games SET fen=?,pgn=?,turn_color=?,host_color=?,visitor_color=?',
                     (fen, pgn, turn, host, visitor))

    def play(self, actor, source, target, promotion='', **extra):
        payload = dict(game_id=1, expected_revision=self.execute('SELECT revision FROM games')[0]['revision'],
                       **{'from': source, 'to': target}, promotion=promotion)
        if actor == 'host':
            payload.update(token=extra.get('token') or self.token(), move='deliberately falsified SAN')
            path = 'api/my_move_submit.php'
        else:
            payload.update(turnstile_token='synthetic', last_move_san='deliberately falsified SAN')
            path = 'api/visitor_move.php'
        payload.update(extra)
        return self.request(path, payload)

    def test_illegal_moves_leave_every_row_and_mailbox_unchanged(self):
        cases = [('e2', 'e5'), ('e2', 'f3'), ('e2', 'e1'), ('a1', 'a4'),
                 ('b1', 'b3'), ('e1', 'e2'), ('e2', 'e2'), ('e7', 'e5')]
        for actor in ('host', 'visitor'):
            for source, target in cases:
                with self.subTest(actor=actor, source=source, target=target):
                    self.setUp()
                    self.position(START_FEN, pgn='', actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    response = self.play(actor, source, target, token=credential)
                    self.assertEqual(response[0], 400)
                    self.assertEqual(json.loads(response[1])['code'], 'illegal_move')
                    self.assert_unchanged(before)
                    self.assertEqual(len(self.mailbox()), 0)

    def test_client_san_is_never_authoritative(self):
        for actor in ('host', 'visitor'):
            with self.subTest(actor=actor):
                self.setUp()
                self.position(START_FEN, pgn='', actor=actor)
                self.assertEqual(self.play(actor, 'e2', 'e4')[0], 200)
                game = self.execute('SELECT pgn,last_move_san FROM games')[0]
                self.assertEqual(game, {'pgn': '1. e4', 'last_move_san': 'e4'})

    def test_host_coordinates_do_not_require_client_san(self):
        self.assertEqual(self.play('host', 'e2', 'e4', move='')[0], 200)
        self.assertEqual(self.execute('SELECT last_move_san FROM games')[0]['last_move_san'], 'e4')

    def test_malformed_coordinates_are_not_truncated_to_legal_squares(self):
        for actor in ('host', 'visitor'):
            for value in ('e2garbage', 'e20', 'i1', None, [], {}, 2):
                with self.subTest(actor=actor, value=value):
                    self.position(START_FEN, pgn='', actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    self.assertEqual(self.play(actor, 'e2', 'e4', token=credential, **{'from':value})[0], 400)
                    self.assert_unchanged(before)

    def test_promotion_validation_never_silently_discards_bad_input(self):
        for actor in ('host', 'visitor'):
            for value in ('queen', 'queen-invalid', 'x', None, [], {}, 1, 'q'):
                with self.subTest(actor=actor, value=value):
                    self.position(START_FEN, pgn='', actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    self.assertEqual(self.play(actor, 'e2', 'e4', promotion=value, token=credential)[0], 400)
                    self.assert_unchanged(before)

    def test_pins_checks_and_illegal_castling_fail_without_consumption(self):
        cases = [
            ('k3r3/8/8/8/8/8/4R3/4K3 w - - 0 1', 'e2', 'f2'),
            ('1k2r3/8/8/8/8/8/R7/4K3 w - - 0 1', 'a2', 'a3'),
            ('k4r2/8/8/8/8/8/8/4K3 w - - 0 1', 'e1', 'f1'),
            ('4kr2/8/8/8/8/8/8/4K2R w K - 0 1', 'e1', 'g1'),
            ('k3r3/8/8/8/8/8/8/4K2R w K - 0 1', 'e1', 'g1'),
            ('4k3/8/8/8/8/8/8/4K2R w - - 0 1', 'e1', 'g1'),
            ('k3r3/8/8/3pP3/8/8/8/4K3 w - d6 0 2', 'e5', 'd6'),
        ]
        for actor in ('host', 'visitor'):
            for fen, source, target in cases:
                with self.subTest(actor=actor, fen=fen):
                    self.position(fen, actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    response = self.play(actor, source, target, token=credential)
                    self.assertEqual(response[0], 400)
                    self.assertEqual(json.loads(response[1])['code'], 'illegal_move')
                    self.assert_unchanged(before)

    def test_all_promotions_for_both_colors_and_actors(self):
        for actor in ('host', 'visitor'):
            for color in ('white', 'black'):
                for piece in ('q', 'r', 'b', 'n'):
                    with self.subTest(actor=actor, color=color, piece=piece):
                        self.setUp()
                        white = color == 'white'
                        self.position('4k3/P7/8/8/8/8/8/4K3 w - - 0 1' if white
                                      else '4k3/8/8/8/8/8/p7/4K3 b - - 0 1', actor=actor)
                        response = self.play(actor, 'a7' if white else 'a2', 'a8' if white else 'a1', piece)
                        self.assertEqual(response[0], 200)
                        game = self.execute('SELECT fen,pgn,last_move_san,status FROM games')[0]
                        self.assertIn('=' + piece.upper(), game['last_move_san'])
                        self.assertIn(piece.upper() if white else piece, game['fen'].split()[0])
                        self.assertEqual(game['status'], 'finished' if piece in ('b','n') else 'active')
                        self.assertEqual(self.execute('SELECT COUNT(*) AS n FROM game_results')[0]['n'], 1 if piece in ('b','n') else 0)

    def test_missing_promotion_is_recoverable_with_same_credential(self):
        self.position('4k3/P7/8/8/8/8/8/4K3 w - - 0 1')
        credential = self.token()
        before = self.snapshot()
        self.assertEqual(self.play('host', 'a7', 'a8', token=credential)[0], 400)
        self.assert_unchanged(before)
        self.assertEqual(self.play('host', 'a7', 'a8', 'n', token=credential)[0], 200)

    def test_legal_castling_en_passant_and_captures_persist_canonical_state(self):
        cases = [
            ('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1', 'e1','g1','O-O','r3k2r/8/8/8/8/8/8/R4RK1 b kq - 1 1'),
            ('4k3/8/8/3pP3/8/8/8/4K3 w - d6 0 2', 'e5','d6','exd6','4k3/8/3P4/8/8/8/8/4K3 b - - 0 2'),
            ('4k3/8/8/3p4/4P3/8/8/4K3 w - - 0 1', 'e4','d5','exd5','4k3/8/8/3P4/8/8/8/4K3 b - - 0 1'),
        ]
        for actor in ('host', 'visitor'):
            for fen, source, target, san, after in cases:
                with self.subTest(actor=actor, san=san):
                    self.setUp()
                    self.position(fen, actor=actor)
                    self.assertEqual(self.play(actor, source, target)[0], 200)
                    game = self.execute('SELECT fen,last_move_san,turn_color FROM games')[0]
                    self.assertEqual(game, {'fen':after, 'last_move_san':san, 'turn_color':'black'})

    def test_terminal_chess_outcomes_atomically_count_once(self):
        cases = [
            ('7k/8/5KQ1/8/8/8/8/8 w - - 0 1', 'g6','g7','mate'),
            ('7k/5K2/8/6Q1/8/8/8/8 w - - 0 1', 'g5','g6','draw'),
            ('4k3/8/8/8/8/8/1r6/2B1K3 w - - 0 1', 'c1','b2','draw'),
            ('4k3/8/8/8/8/8/8/R3K3 w - - 99 50', 'a1','a2','draw'),
        ]
        for actor in ('host', 'visitor'):
            for fen, source, target, outcome in cases:
                with self.subTest(actor=actor, outcome=outcome, fen=fen):
                    self.setUp()
                    self.position(fen, actor=actor)
                    credential = self.token()
                    self.assertEqual(self.play(actor, source, target, token=credential)[0], 200)
                    result = ('host' if actor == 'host' else 'world') if outcome == 'mate' else 'draw'
                    self.assertEqual(self.execute('SELECT result FROM game_results'), [{'result':result}])
                    self.assertEqual(self.execute('SELECT status,revision FROM games'), [{'status':'finished','revision':1}])
                    before = self.snapshot()
                    self.assertIn(self.play(actor, source, target, token=credential)[0], (403, 409))
                    self.assert_unchanged(before)

    def test_threefold_uses_history_in_moves_and_finished_game_transition(self):
        fen = 'rnbqkb1r/pppppppp/5n2/8/8/8/PPPPPPPP/RNBQKBNR b KQkq - 7 4'
        pgn = '1. Nf3 Nf6 2. Ng1 Ng8 3. Nf3 Nf6 4. Ng1'
        for actor in ('host', 'visitor'):
            with self.subTest(actor=actor):
                self.setUp()
                self.position(fen, pgn=pgn, actor=actor)
                self.assertEqual(self.play(actor, 'f6', 'g8')[0], 200)
                self.assertEqual(self.execute('SELECT result FROM game_results'), [{'result':'draw'}])
                # Verify transition detection also uses PGN, rather than FEN alone.
                self.execute('DELETE FROM game_results')
                credential = self.execute("SELECT token FROM tokens WHERE purpose='next_game' AND revoked_at IS NULL")[0]['token']
                self.assertEqual(self.request('api/host_next_game.php', {'token':credential})[0], 200)
                self.assertEqual(self.execute('SELECT result FROM game_results'), [{'result':'draw'}])

    def test_stored_history_disagreement_never_rewrites_either_source(self):
        for actor in ('host', 'visitor'):
            for pgn in ('1. e4', '1. e4 e5 garbage', '1. e4 e5 2. Nf3', '[Result "0-1"]\n\n*'):
                with self.subTest(actor=actor, pgn=pgn):
                    self.position(START_FEN, pgn=pgn, actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    response = self.play(actor, 'e2', 'e4', token=credential)
                    self.assertEqual(response[0], 409)
                    self.assertEqual(json.loads(response[1])['code'], 'state_inconsistent')
                    self.assert_unchanged(before)

    def test_stored_turn_color_must_agree_with_fen(self):
        for actor in ('host', 'visitor'):
            with self.subTest(actor=actor):
                self.position(START_FEN, pgn='', actor=actor)
                self.execute("UPDATE games SET turn_color='black',host_color=visitor_color,visitor_color=host_color")
                credential = self.token()
                before = self.snapshot()
                response = self.play(actor, 'e7', 'e5', token=credential)
                self.assertEqual(response[0], 409)
                self.assertEqual(json.loads(response[1])['code'], 'state_inconsistent')
                self.assert_unchanged(before)

    def test_missing_and_malformed_final_history_plies_fail_without_repair(self):
        e4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1'
        e5 = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2'
        for actor in ('host', 'visitor'):
            for fen, pgn, source, target in [(e4,'','e7','e5'), (e5,'1. e4','g1','f3'),
                                             (e5,'1. e4 e5 2. garbage','g1','f3'),
                                             (e4,'1. e4 {preserve this note}','e7','e5')]:
                with self.subTest(actor=actor, pgn=pgn):
                    self.position(fen, pgn=pgn, actor=actor)
                    credential = self.token()
                    before = self.snapshot()
                    response = self.play(actor, source, target, token=credential)
                    self.assertEqual(response[0], 409)
                    self.assertEqual(json.loads(response[1])['code'], 'state_inconsistent')
                    self.assert_unchanged(before)

    def test_invalid_utf8_and_oversized_history_fail_without_writes(self):
        before = self.snapshot()
        probe = self.php_script("require __DIR__.'/db.php'; try { chess_engine_request(['fen'=>starting_fen(),'pgn'=>chr(255)]); exit(1); } catch (ChessStateException $e) { echo $e->responseCode; }")
        self.assertEqual((probe.returncode, probe.stdout), (0, 'state_inconsistent'))
        self.assert_unchanged(before)
        self.position(START_FEN, pgn=' ' * 65537)
        credential = self.token()
        before = self.snapshot()
        response = self.play('host', 'e2', 'e4', token=credential)
        self.assertEqual(response[0], 409)
        self.assertEqual(json.loads(response[1])['code'], 'state_inconsistent')
        self.assert_unchanged(before)

    def fake_node(self, source):
        script = self.app / 'fake-node'
        script.write_text('#!/usr/bin/env python3\n' + source)
        script.chmod(0o700)
        (self.app / 'data/node-runtime').write_text(str(script))

    def assert_engine_failure_is_atomic(self, pgn=''):
        for actor in ('host', 'visitor'):
            with self.subTest(actor=actor):
                self.position(START_FEN, pgn=pgn, actor=actor)
                credential = self.token()
                before = self.snapshot()
                response = self.play(actor, 'e2', 'e4', token=credential)
                self.assertEqual(response[0], 503)
                self.assertEqual(json.loads(response[1])['code'], 'chess_unavailable')
                self.assert_unchanged(before)
                self.assertEqual(len(self.mailbox()), 0)

    def test_missing_node_fails_closed(self):
        (self.app / 'data/node-runtime').write_text('/nonexistent/synthetic-node')
        self.assert_engine_failure_is_atomic()

    def test_malformed_or_incomplete_engine_responses_fail_closed(self):
        for output in ('not json', '{}', '{"ok":true}', '{"ok":false,"code":"engine_error"}',
                       '{"ok":true,"before":{"ok":true,"over":false,"reason":null,"winner":null}}'):
            with self.subTest(output=output):
                self.fake_node('print(' + repr(output) + ')\n')
                self.assert_engine_failure_is_atomic()

    def test_failed_engine_exit_and_output_flood_fail_closed(self):
        for source in ('import sys; print("{}"); sys.exit(1)\n',
                       'print("x" * 150000)\n', 'import sys; sys.stderr.write("x" * 150000)\n'):
            with self.subTest(source=source):
                self.fake_node(source)
                self.assert_engine_failure_is_atomic()

    def test_engine_timeout_is_bounded_and_rolls_back(self):
        self.fake_node('import time; time.sleep(10)\n')
        start = time.monotonic()
        self.assert_engine_failure_is_atomic(pgn=' ' * 60000)
        self.assertLess(time.monotonic() - start, 8)

    def test_disabled_process_functions_fail_closed(self):
        previous_options, previous_base = self.php_options, self.base
        for function in ('proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'stream_select'):
            with self.subTest(function=function):
                try:
                    self.php_options = [*previous_options, '-d', 'disable_functions=mail,shell_exec,'+function]
                    with self.second_worker() as other:
                        self.base = other.base
                        self.assert_engine_failure_is_atomic()
                finally:
                    self.php_options, self.base = previous_options, previous_base

    def test_missing_engine_script_or_asset_fails_closed(self):
        for name in ('scripts/chess_rules.js', 'assets/chess.min.js'):
            with self.subTest(name=name):
                original = self.app / name
                hidden = original.with_suffix('.hidden')
                original.rename(hidden)
                try: self.assert_engine_failure_is_atomic()
                finally: hidden.rename(original)

    def test_legacy_helper_itself_no_longer_applies_impossible_moves(self):
        before = self.snapshot()
        probe = self.php_script("require __DIR__.'/db.php'; try { apply_move_to_fen(starting_fen(),'e2','e5','','white'); exit(1); } catch (ChessStateException $e) { echo $e->responseCode; }")
        self.assertEqual(probe.returncode, 0)
        self.assertEqual(probe.stdout, 'illegal_move')
        self.assert_unchanged(before)

    def test_readonly_preflight_preserves_database_and_discloses_no_credentials(self):
        credential = self.token()
        before = self.snapshot()
        file_before = self.db.read_bytes()
        result = subprocess.run(self.php_options + [str(self.app / 'scripts/check_chess.php'), '--database='+str(self.db)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertTrue(json.loads(result.stdout)['ok'])
        self.assertTrue(credential not in result.stdout + result.stderr)
        self.assert_unchanged(before)
        self.assertEqual(self.db.read_bytes(), file_before)
        self.execute("UPDATE games SET pgn='1. e4'")
        before = self.snapshot()
        result = subprocess.run(self.php_options + [str(self.app / 'scripts/check_chess.php'), '--database='+str(self.db)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assert_unchanged(before)
        self.assertEqual(self.request('scripts/check_chess.php')[0], 404)

    def test_visitor_email_host_link_move_and_public_state_round_trip(self):
        self.position(START_FEN, pgn='', actor='visitor')
        self.assertEqual(self.play('visitor', 'e2', 'e4')[0], 200)
        self.assertEqual(len(self.mailbox()), 1)
        credential = self.execute("SELECT token FROM tokens WHERE purpose='your_move' AND revoked_at IS NULL")[0]['token']
        before = self.snapshot()
        self.assertEqual(self.request('my_move.php?token='+credential)[0], 200)
        self.assert_unchanged(before)
        self.assertEqual(self.play('host', 'e7', 'e5', token=credential)[0], 200)
        public = json.loads(self.request('api/state.php')[1])
        self.assertEqual((public['pgn'], public['turn_color'], public['revision']), ('1. e4 e5', 'white', 2))
        self.assertEqual(public['fen'], 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2')
        self.assertEqual(len(self.mailbox()), 1)


if __name__ == '__main__':
    unittest.main(verbosity=2)
