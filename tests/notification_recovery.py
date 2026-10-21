#!/usr/bin/env python3
"""Phase 4 integration regressions; includes all Phase 1–3 cases."""
import json
import subprocess
import concurrent.futures
import hashlib
import time
import unittest
import chess_legality as legality
from host_authorization import START_FEN


class NotificationRecovery(legality.ChessLegality):
    def submission(self, actor='host', key='a'*32, credential=None, **extra):
        payload = {'submission_id':key, 'game_id':1, 'expected_revision':0,
                   'from':'e2', 'to':'e4', 'promotion':''}
        if actor == 'host': payload['token'] = credential or self.token()
        else: payload['turnstile_token'] = 'synthetic'
        payload.update(extra)
        path = 'api/my_move_submit.php' if actor == 'host' else 'api/visitor_move.php'
        return self.request(path, payload)

    def test_lost_host_response_replays_success_without_another_move(self):
        credential = self.token()
        self.assertEqual(self.submission(credential=credential)[0], 200)
        before = self.snapshot()
        response = self.submission(credential=credential)
        self.assertEqual(response[0], 200)
        self.assertTrue(json.loads(response[1])['replayed'])
        self.assert_unchanged(before)
        self.assertTrue(credential not in response[1])

    def test_failed_mail_keeps_move_and_durable_notification(self):
        self.position(START_FEN, pgn='', actor='visitor')
        (self.app/'data/mail-mode').write_text('fail')
        response = self.submission('visitor')
        self.assertEqual(response[0], 200)
        self.assertTrue(json.loads(response[1])['ok'])
        self.assertEqual(self.execute('SELECT revision FROM games'), [{'revision':1}])
        jobs = self.execute('SELECT status,attempts FROM notification_outbox')
        self.assertEqual(jobs, [{'status':'pending','attempts':1}])

    def cli(self, name='notifications', *flags):
        result = subprocess.run(self.php_options + [str(self.app/f'scripts/{name}.php'),
            '--database='+str(self.db), *flags], capture_output=True, text=True, timeout=10)
        for row in self.execute('SELECT token FROM tokens'):
            self.assertTrue(row['token'] not in result.stdout + result.stderr, 'CLI exposed credential')
        return result

    def queue(self):
        result = self.cli('notifications', '--queue-current')
        self.assertEqual(result.returncode, 0)
        return self.execute('SELECT id FROM notification_outbox')[0]['id']

    def test_visitor_receipt_replay_needs_no_fresh_captcha(self):
        self.position(START_FEN, pgn='', actor='visitor')
        self.assertEqual(self.submission('visitor')[0], 200)
        before = self.snapshot()
        result = self.submission('visitor', turnstile_token='')
        self.assertEqual(result[0], 200)
        self.assertTrue(json.loads(result[1])['replayed'])
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()), 1)

    def test_same_id_different_move_is_conflict_for_both_actors(self):
        for actor in ('visitor','host'):
            with self.subTest(actor=actor):
                self.setUp(); self.position(START_FEN,pgn='',actor=actor)
                token=self.token()
                self.assertEqual(self.submission(actor,credential=token)[0],200)
                before=self.snapshot()
                response=self.submission(actor,credential=token,to='e3')
                self.assertEqual(response[0],409)
                self.assertEqual(json.loads(response[1])['code'],'submission_conflict')
                self.assert_unchanged(before)

    def test_receipt_lookup_is_read_only_and_never_returns_a_credential(self):
        token=self.token()
        before=self.snapshot()
        self.assertEqual(self.request('api/my_move_submit.php',{'action':'receipt','token':token})[0],404)
        self.assert_unchanged(before)
        self.assertEqual(self.submission(credential=token)[0],200)
        before=self.snapshot()
        for state in ('used','expired','revoked'):
            if state=='expired': self.execute("UPDATE tokens SET expires_at=datetime('now','-1 day')")
            if state=='revoked': self.execute("UPDATE tokens SET revoked_at=CURRENT_TIMESTAMP")
            before=self.snapshot()
            response=self.request('api/my_move_submit.php',{'action':'receipt','token':token})
            self.assertEqual(response[0],200)
            body=json.loads(response[1]);self.assertTrue(body['ok'])
            self.assertTrue('next_game_token' not in body)
            self.assertTrue(token not in response[1])
            self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()),0)

    def test_invalid_credentials_cannot_query_someone_elses_receipt(self):
        token=self.token();self.assertEqual(self.submission(credential=token)[0],200)
        before=self.snapshot()
        for credential in ('','not-a-token',self.token(used=1)):
            before=self.snapshot()
            response=self.request('api/my_move_submit.php',{'action':'receipt','token':credential})
            self.assertIn(response[0],(401,404));self.assert_unchanged(before)

    def test_submission_identifier_validation_is_non_mutating(self):
        token=self.token()
        for key in ('short','A'*32,'a'*33,[],123):
            before=self.snapshot()
            self.assertEqual(self.submission(key=key,credential=token)[0],400)
            self.assert_unchanged(before)

    def test_concurrent_identical_host_and_visitor_requests_replay_once(self):
        for actor in ('host','visitor'):
            with self.subTest(actor=actor):
                self.setUp();self.position(START_FEN,pgn='',actor=actor);token=self.token()
                with self.second_worker() as other, concurrent.futures.ThreadPoolExecutor(2) as pool:
                    futures=[pool.submit(instance.submission,actor,credential=token) for instance in (self,other)]
                    responses=[f.result() for f in futures]
                self.assertEqual(sorted(r[0] for r in responses),[200,200])
                self.assertEqual(sum(bool(json.loads(r[1]).get('replayed')) for r in responses),1)
                self.assertEqual(self.execute('SELECT revision FROM games'),[{'revision':1}])
                self.assertEqual(len(self.execute('SELECT * FROM move_receipts')),1)
                self.assertEqual(len(self.mailbox()),int(actor=='visitor'))

    def test_notification_insert_failure_rolls_back_move_and_receipt(self):
        self.position(START_FEN,pgn='',actor='visitor')
        self.execute("CREATE TRIGGER fail_outbox BEFORE INSERT ON notification_outbox BEGIN SELECT RAISE(ABORT,'Synthetic failure'); END")
        before=self.snapshot()
        try:
            self.assertEqual(self.submission('visitor')[0],500)
            self.assert_unchanged(before);self.assertEqual(len(self.mailbox()),0)
        finally: self.execute('DROP TRIGGER fail_outbox')

    def test_receipt_insert_failure_rolls_back_host_token_and_move(self):
        token=self.token()
        self.execute("CREATE TRIGGER fail_receipt BEFORE INSERT ON move_receipts BEGIN SELECT RAISE(ABORT,'Synthetic failure'); END")
        before=self.snapshot()
        try:
            self.assertEqual(self.submission(credential=token)[0],500)
            self.assert_unchanged(before)
        finally:self.execute('DROP TRIGGER fail_receipt')

    def test_email_failure_retries_after_due_time_and_stops_after_acceptance(self):
        self.position(START_FEN,pgn='',actor='visitor')
        (self.app/'data/mail-mode').write_text('throw')
        self.assertEqual(self.submission('visitor')[0],200)
        self.assertEqual(len(self.mailbox()),1)
        self.assertEqual(self.cli('notifications','--drain').returncode,0)
        self.assertEqual(len(self.mailbox()),1)
        self.execute('UPDATE notification_outbox SET next_attempt_at=0')
        (self.app/'data/mail-mode').write_text('accept')
        self.assertEqual(self.cli('notifications','--drain').returncode,0)
        self.assertEqual(self.execute('SELECT status,attempts FROM notification_outbox'),[{'status':'accepted','attempts':2}])
        self.assertEqual(len(self.mailbox()),2)
        self.assertEqual(self.mailbox()[0]['headers'],self.mailbox()[1]['headers'])
        self.cli('notifications','--drain');self.assertEqual(len(self.mailbox()),2)
        self.assertEqual(self.execute('SELECT revision FROM games'),[{'revision':1}])

    def test_worker_default_and_http_are_read_only(self):
        self.queue();before=self.snapshot()
        self.assertEqual(self.cli().returncode,0)
        for path in ('scripts/notifications.php','scripts/migrate_phase4.php'):
            self.assertEqual(self.request(path)[0],404)
            self.assertEqual(self.request(path,{},method='POST')[0],404)
        self.assert_unchanged(before);self.assertEqual(len(self.mailbox()),0)

    def test_worker_two_processes_claim_one_email(self):
        self.queue()
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            results=list(pool.map(lambda _:self.cli('notifications','--drain'),range(2)))
        self.assertEqual([r.returncode for r in results],[0,0])
        self.assertEqual(len(self.mailbox()),1)
        self.assertEqual(self.execute('SELECT attempts FROM notification_outbox'),[{'attempts':1}])

    def test_worker_reclaims_abandoned_lease_but_respects_active_one(self):
        self.queue()
        self.execute("UPDATE notification_outbox SET status='sending',attempts=1,claim='synthetic',lease_until=?",(int(time.time())+600,))
        before=self.snapshot();self.cli('notifications','--drain');self.assert_unchanged(before)
        self.execute('UPDATE notification_outbox SET lease_until=0')
        self.cli('notifications','--drain')
        self.assertEqual(len(self.mailbox()),1)
        self.assertEqual(self.execute('SELECT status,attempts,claim,lease_until FROM notification_outbox'),
                         [{'status':'accepted','attempts':2,'claim':None,'lease_until':None}])

    def test_worker_rotates_expired_job_only_for_its_original_turn(self):
        self.queue();old=self.execute('SELECT id,token FROM tokens')[0]
        self.execute("UPDATE tokens SET expires_at=datetime('now','-1 day')")
        self.cli('notifications','--drain')
        self.assertEqual(len(self.mailbox()),1)
        self.assertTrue(old['token'] not in self.mailbox()[0]['body'])
        self.assertTrue(self.execute('SELECT revoked_at FROM tokens WHERE id=?',(old['id'],))[0]['revoked_at'])
        self.assertEqual(self.execute('SELECT DISTINCT game_id,game_revision,purpose FROM tokens'),
                         [{'game_id':1,'game_revision':0,'purpose':'your_move'}])
        self.assertEqual(self.execute('SELECT revision FROM games'),[{'revision':0}])

    def test_worker_never_sends_stale_used_revoked_or_wrong_turn_jobs(self):
        mutations=["UPDATE games SET revision=revision+1", "UPDATE tokens SET used=1",
                   "UPDATE tokens SET revoked_at=CURRENT_TIMESTAMP", "UPDATE games SET turn_color='black'",
                   "UPDATE games SET status='finished'"]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                self.setUp();self.queue();self.execute(mutation)
                tokens=self.execute('SELECT COUNT(*) AS n FROM tokens')
                self.assertEqual(self.cli('notifications','--drain').returncode,0)
                self.assertEqual(self.execute('SELECT status FROM notification_outbox'),[{'status':'obsolete'}])
                self.assertEqual(len(self.mailbox()),0)
                self.assertEqual(tokens,self.execute('SELECT COUNT(*) AS n FROM tokens'))

    def test_worker_does_not_follow_old_game_into_a_new_game(self):
        self.queue();self.execute("UPDATE games SET status='finished'")
        self.execute("INSERT INTO games(id,host_color,visitor_color,turn_color,status,fen,pgn) VALUES(2,'white','black','white','active',?,'')",(START_FEN,))
        self.cli('notifications','--drain')
        self.assertEqual(len(self.mailbox()),0)
        self.assertEqual(len(self.execute('SELECT * FROM tokens')),1)
        self.assertEqual(self.execute('SELECT status FROM notification_outbox'),[{'status':'obsolete'}])

    def test_worker_attempt_limit_and_explicit_retry(self):
        self.queue();self.execute('UPDATE notification_outbox SET attempts=7')
        (self.app/'data/mail-mode').write_text('fail')
        self.cli('notifications','--drain')
        self.assertEqual(self.execute('SELECT status,attempts FROM notification_outbox'),[{'status':'failed','attempts':8}])
        self.cli('notifications','--drain');self.assertEqual(len(self.mailbox()),1)
        (self.app/'data/mail-mode').write_text('accept')
        self.cli('notifications','--retry-failed','--drain')
        self.assertEqual(self.execute('SELECT status,attempts FROM notification_outbox'),[{'status':'accepted','attempts':1}])

    def test_crash_at_final_attempt_cannot_bypass_limit(self):
        self.queue();self.execute("UPDATE notification_outbox SET status='sending',attempts=8,lease_until=0")
        self.cli('notifications','--drain')
        self.assertEqual(self.execute('SELECT status FROM notification_outbox'),[{'status':'failed'}])
        self.assertEqual(len(self.mailbox()),0)

    def test_post_commit_claim_failure_still_returns_accepted_move(self):
        self.position(START_FEN,pgn='',actor='visitor')
        self.execute("CREATE TRIGGER fail_claim BEFORE UPDATE ON notification_outbox BEGIN SELECT RAISE(ABORT,'Synthetic claim failure'); END")
        try:
            response=self.submission('visitor');self.assertEqual(response[0],200)
            self.assertEqual(json.loads(response[1])['notification'],'queued')
            self.assertEqual(self.execute('SELECT revision FROM games'),[{'revision':1}])
            self.assertEqual(self.execute('SELECT status,attempts FROM notification_outbox'),[{'status':'pending','attempts':0}])
        finally:self.execute('DROP TRIGGER fail_claim')
        self.cli('notifications','--drain');self.assertEqual(len(self.mailbox()),1)

    def test_mail_accept_before_ack_failure_is_recoverable_at_least_once(self):
        self.queue()
        self.execute("CREATE TRIGGER fail_ack BEFORE UPDATE ON notification_outbox WHEN NEW.status='accepted' BEGIN SELECT RAISE(ABORT,'Synthetic acknowledgement failure'); END")
        try:self.cli('notifications','--drain')
        finally:self.execute('DROP TRIGGER fail_ack')
        self.assertEqual(len(self.mailbox()),1)
        self.assertEqual(self.execute('SELECT status FROM notification_outbox'),[{'status':'sending'}])
        self.execute('UPDATE notification_outbox SET lease_until=0')
        self.cli('notifications','--drain');self.assertEqual(len(self.mailbox()),2)
        self.assertEqual(self.mailbox()[0]['headers'],self.mailbox()[1]['headers'])

    def test_completion_receipt_never_returns_next_game_credential(self):
        self.position('rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2',pgn='1. f3 e5 2. g4')
        token=self.token();response=self.submission(credential=token,**{'from':'d8','to':'h4'})
        self.assertEqual(response[0],200);self.assertTrue(json.loads(response[1])['next_game_token'])
        before=self.snapshot()
        replay=self.submission(credential=token,**{'from':'d8','to':'h4'})
        self.assertEqual(replay[0],200)
        self.assertTrue('next_game_token' not in json.loads(replay[1]))
        self.assertTrue(json.loads(replay[1])['completion_required'])
        self.assert_unchanged(before)
        self.assertEqual(len(self.mailbox()),1)
        self.assertIn('Game complete.',self.mailbox()[0]['body'])

    def test_operator_can_queue_email_after_previous_transport_acceptance(self):
        self.queue();self.cli('notifications','--drain')
        self.cli('notifications','--queue-current','--drain')
        self.assertEqual(len(self.mailbox()),2)
        self.assertEqual(self.execute('SELECT revision FROM games'),[{'revision':0}])

    def test_schema2_reads_work_but_new_writes_require_explicit_migration(self):
        token=self.token()
        self.execute('DROP TABLE notification_outbox');self.execute('DROP TABLE move_receipts')
        self.execute('UPDATE app_meta SET schema_version=2')
        try:
            oldrows=self.execute('SELECT * FROM games');oldtokens=self.execute('SELECT * FROM tokens')
            self.assertEqual(self.request('api/state.php')[0],200)
            self.assertEqual(self.request('my_move.php?token='+token)[0],200)
            self.assertEqual(self.submission(credential=token)[0],503)
            before=hashlib.sha256(self.db.read_bytes()).hexdigest()
            self.assertEqual(self.cli('migrate_phase4').returncode,0)
            self.assertEqual(before,hashlib.sha256(self.db.read_bytes()).hexdigest())
            self.assertEqual(self.cli('migrate_phase4','--apply').returncode,0)
            self.assertEqual(self.execute('SELECT * FROM games'),oldrows)
            self.assertTrue(self.execute('SELECT * FROM tokens')==oldtokens)
            self.assertEqual(self.execute('SELECT COUNT(*) AS n FROM notification_outbox'),[{'n':0}])
            before=self.snapshot();self.assertEqual(self.cli('migrate_phase4','--apply').returncode,0)
            self.assert_unchanged(before)
        finally:
            # Restore the temporary fixture even if an assertion fails.
            self.cli('migrate_phase4','--apply')

    def test_transition_mail_failure_and_lost_response_preserve_one_successor(self):
        self.position('rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2',pgn='1. f3 e5 2. g4',actor='host')
        self.assertEqual(self.submission(**{'from':'d8','to':'h4'})[0],200)
        credential=self.execute("SELECT token FROM tokens WHERE purpose='next_game'")[0]['token']
        (self.app/'data/mail-mode').write_text('throw')
        response=self.request('api/host_next_game.php',{'token':credential})
        self.assertEqual(response[0],200)
        successor=json.loads(response[1])['new_game_id']
        before=self.snapshot()
        response=self.request('api/host_next_game.php',{'token':credential})
        self.assertEqual(response[0],200);self.assertEqual(json.loads(response[1])['new_game_id'],successor)
        receipt=self.request('api/my_move_submit.php',{'action':'receipt','token':credential})
        self.assertEqual(receipt[0],200);self.assertEqual(json.loads(receipt[1])['new_game_id'],successor)
        self.assert_unchanged(before)
        self.assertEqual(self.execute("SELECT status,attempts FROM notification_outbox WHERE game_id=?",(successor,)),
                         [{'status':'pending','attempts':1}])
        self.assertEqual(len(self.mailbox()),2)

    def test_fresh_visitor_without_captcha_cannot_create_receipt_or_job(self):
        self.position(START_FEN,pgn='',actor='visitor');before=self.snapshot()
        self.assertEqual(self.submission('visitor',turnstile_token='')[0],400)
        self.assert_unchanged(before)

    def test_turnstile_rejection_and_network_failure_never_create_new_receipts(self):
        self.position(START_FEN,pgn='',actor='visitor')
        for mode in ('reject','network_failure'):
            (self.app/'data/captcha-mode').write_text(mode);before=self.snapshot()
            response=self.submission('visitor')
            self.assertEqual(response[0],400)
            self.assertEqual(json.loads(response[1])['code'],'captcha_failed')
            self.assert_unchanged(before);self.assertEqual(len(self.mailbox()),0)
        (self.app/'data/captcha-mode').unlink()
        self.assertEqual(self.submission('visitor')[0],200)
        (self.app/'data/captcha-mode').write_text('network_failure');before=self.snapshot()
        self.assertEqual(self.submission('visitor')[0],200) # Accepted receipt survives verification outage.
        self.assert_unchanged(before)

    def test_notification_worker_bounds_batch_and_redacts_log_credentials(self):
        result=self.php_script('require __DIR__."/db.php"; $db=get_db(); begin_write_transaction($db); '
            'for($i=0;$i<21;$i++) { $t=insert_host_move_token($db,1,default_host_token_expiry()); '
            'enqueue_host_notification($db,1,$t); } $db->commit();')
        self.assertEqual(result.returncode,0)
        self.assertEqual(self.cli('notifications','--drain').returncode,0)
        self.assertEqual(len(self.mailbox()),20)
        self.assertEqual(self.execute("SELECT COUNT(*) AS n FROM notification_outbox WHERE status='pending'"),[{'n':1}])
        log=(self.app/'data/application.log').read_text()
        for row in self.execute('SELECT token FROM tokens'):
            self.assertTrue(row['token'] not in log, 'Worker log exposed a credential')
        self.assertTrue('token=' not in log)

    def test_admin_reset_compatibility_preserves_referenced_rows_in_fixture_only(self):
        # This is an isolated destructive admin-action compatibility test, never recovery.
        self.position(START_FEN,pgn='',actor='visitor');self.assertEqual(self.submission('visitor')[0],200)
        response=self.request('api/admin_reset.php',{'admin_key':'synthetic-admin-not-used'})
        self.assertEqual(response[0],200)
        self.assertEqual(len(self.execute('SELECT * FROM move_receipts')),1)
        self.assertEqual(len(self.execute('SELECT * FROM notification_outbox')),1)
        self.assertTrue(self.execute('SELECT revoked_at FROM tokens ORDER BY id LIMIT 1')[0]['revoked_at'])


if __name__ == '__main__': unittest.main(verbosity=2)
