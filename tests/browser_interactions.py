#!/usr/bin/env python3
"""Isolated frontend interaction checks; no PHP, outside requests, or real writes.

Strips PHP from the public shell and serves repository assets in Playwright routes.
API/Turnstile responses are fixtures: this verifies client behavior, not PHP rendering
or authoritative endpoint validation. Requires existing Node, Playwright and Chromium.
Run: python3 tests/browser_interactions.py
"""
import json
import mimetypes
import os
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[1]
BASE = 'https://chess.test/chess/'
HTML = re.sub(r'<\?[\s\S]*?\?>', '', (ROOT / 'index.php').read_text())
# Preserve the literal page title when stripping PHP; otherwise accessibility
# tooling would report an empty title that the real PHP page does not have.
HTML = HTML.replace('<title></title>', '<title>The Internet Gambit | patrickmicka.com</title>')


def position(pgn='', move=None):
    script = """const {Chess}=require('./assets/chess.min.js'); const g=new Chess();
      if(process.argv[1])g.load_pgn(process.argv[1]);
      if(process.argv[2])g.move(JSON.parse(process.argv[2]));
      console.log(JSON.stringify({fen:g.fen(),pgn:g.pgn(),last_move_san:g.history().at(-1)||null,
        turn_color:g.turn()==='w'?'white':'black'}));"""
    return json.loads(subprocess.check_output(
        ['node', '-e', script, pgn, json.dumps(move) if move else ''], cwd=ROOT))


def game(color='white', pgn=''):
    return dict(ok=True, id=1, revision=0, **position(pgn),
                you_color='black' if color == 'white' else 'white', visitor_color=color,
                status='active', updated_at='2026-11-10 00:00:00',
                score=dict(host_wins=0, world_wins=0, draws=0))


class Fixture:
    def __init__(self, browser, width=390, color='white', pgn=''):
        self.state = game(color, pgn)
        self.posts, self.errors, self.receipts = [], [], {}
        self.fail_state = False
        self.outcome = 'accept'
        self.held = []
        self.context = browser.new_context(viewport={'width': width, 'height': 900})
        self.context.add_init_script("""const nativeInterval=window.setInterval;
          window.setInterval=(fn,ms,...args)=>ms===20000 ? (window.runPoll=fn,1) : nativeInterval(fn,ms,...args);""")
        self.context.route('**/*', self.route)
        self.page = self.context.new_page()
        self.page.on('pageerror', lambda e: self.errors.append(str(e)))
        self.page.goto(BASE)
        self.page.wait_for_function("document.body.dataset.gameId==='1'")

    def route(self, route):
        request = route.request
        url = urlparse(request.url)
        if url.hostname == 'challenges.cloudflare.com' and request.method == 'GET':
            return route.fulfill(content_type='application/javascript',
                                 body="""window.turnstile={
                  render:(el,options)=>{window.verificationOptions=options;
                    window.renderCount=(window.renderCount||0)+1;
                    el.innerHTML='<div style="width:300px;height:65px">CAPTCHA fixture</div>';
                    return 'fixture-widget';},
                  reset:(id)=>{if(id!=='fixture-widget')throw Error('Wrong widget ID');},
                  remove:(id)=>{if(id!=='fixture-widget')throw Error('Wrong widget ID');
                    document.querySelector('#turnstileWidget').innerHTML='';}
                };window.onTurnstileReady();""")
        if url.hostname != 'chess.test':
            return route.abort()
        path = url.path.removeprefix('/chess/')
        if request.method == 'POST':
            assert path == 'api/visitor_move.php', path
            data = request.post_data_json
            self.posts.append(data)
            receipt = self.receipts.get(data['submission_id'])
            if receipt:
                return route.fulfill(json=receipt)
            if data.get('action') == 'receipt':
                return route.fulfill(status=404, json={'ok': False, 'code': 'receipt_missing'})
            if self.outcome == 'malformed':
                return route.fulfill(body='not JSON')
            if self.outcome == 'reject':
                return route.fulfill(status=400, json={'ok': False, 'code': 'captcha_failed',
                                                     'error': 'CAPTCHA verification failed'})
            if self.outcome == 'hold':
                self.held.append(route)
                return
            assert data['turnstile_token'] == 'fixture-token'
            assert data['expected_revision'] == self.state['revision']
            self.state.update(position(self.state['pgn'], {k: data[k] for k in ('from', 'to', 'promotion')}))
            self.state['revision'] += 1
            receipt = {'ok': True, 'state': self.state.copy()}
            self.receipts[data['submission_id']] = receipt
            if self.outcome == 'lost':
                return route.abort()
            return route.fulfill(json=receipt)
        assert request.method == 'GET'
        if path in ('', 'index.php'):
            return route.fulfill(body=HTML, content_type='text/html')
        if path == 'api/state.php':
            if self.fail_state:
                return route.fulfill(status=503, json={'ok': False})
            return route.fulfill(json=self.state)
        file = ROOT / path
        if file.is_file():
            return route.fulfill(body=file.read_bytes(),
                                 content_type=mimetypes.guess_type(str(file))[0] or 'application/octet-stream')
        return route.abort()

    def square(self, name):
        return self.page.locator(f'#board .sq[aria-label^="{name},"]')

    def select(self, source='e2', target='e4'):
        self.square(source).click()
        self.square(target).click()

    def verify(self):
        self.page.evaluate("window.onTurnstileSuccess('fixture-token')")

    def close(self):
        assert not self.errors, self.errors
        self.context.close()


def wait_text(page, selector, text):
    page.wait_for_function('([s,t])=>document.querySelector(s).textContent.includes(t)', arg=[selector, text])


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                                    args=['--no-sandbox', '--disable-background-networking'])
        for width in (320, 390, 768, 1280):
            for color in ('white', 'black'):
                f = Fixture(browser, width, color, '' if color == 'white' else '1. e4')
                page = f.page
                source, target = ('e2', 'e4') if color == 'white' else ('e7', 'e5')
                assert page.locator('#btnSubmit').is_disabled()
                assert page.locator('#btnCancel').is_visible()
                assert page.locator('#btnCancel').is_disabled()
                assert page.locator('#detailsToggle, #hudDetailsPanel, .board-help, #notationStatus').count() == 0
                assert page.locator('#historyStatus, #historyNotice').count() == 0
                assert page.locator('.status-stack').is_hidden()
                assert page.locator('#submissionHelp').is_visible()
                assert page.locator('#boardHelp').evaluate('(e)=>getComputedStyle(e).clipPath') == 'inset(50%)'
                assert page.locator('.turnstile-wrap').is_hidden()
                assert page.evaluate('window.renderCount||0') == 0
                assert page.locator('#board .sq[tabindex="0"]').count() == 1
                f.square(source).focus()
                page.keyboard.press('Enter')
                page.keyboard.press('ArrowUp')
                page.keyboard.press('ArrowUp')
                assert f.square(target).evaluate('(e)=>e===document.activeElement')
                page.keyboard.press('Space')
                assert f.square(target).evaluate('(e)=>e===document.activeElement'), 'rerender lost focus'
                assert page.locator('#btnSubmit').is_disabled(), 'missing CAPTCHA enabled submit'
                assert page.locator('.selected-move').is_visible()
                assert page.locator('.turnstile-wrap').is_visible()
                assert page.evaluate('window.renderCount') == 1
                with page.expect_response(lambda r: r.url.endswith('/api/state.php')):
                    page.evaluate('window.runPoll()')
                page.evaluate('()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')
                assert page.locator('#statusMsg').inner_text() == ''
                assert page.locator('#submissionHelp').is_visible()
                assert f.square(target).locator('img').count() == 1, 'poll erased preview'
                assert f.square(source).locator('img').count() == 0
                f.verify()
                assert page.locator('#btnSubmit').is_enabled()
                top = page.locator('#btnSubmit').bounding_box()['y']
                page.evaluate('window.onTurnstileExpired()')
                assert page.locator('#btnSubmit').is_disabled()
                assert 'expired' in page.locator('#submissionHelp').inner_text()
                page.evaluate('window.onTurnstileError()')
                assert 'failed' in page.locator('#submissionHelp').inner_text()
                f.verify()
                assert page.locator('#btnSubmit').is_enabled()
                assert abs(top - page.locator('#btnSubmit').bounding_box()['y']) < 1
                page.keyboard.press('Escape')
                assert page.locator('.selected-move').is_hidden()
                assert page.locator('#btnSubmit').is_disabled()
                assert page.locator('#board .sq.last').count() in (0, 2)
                f.select(source, target)
                f.verify()
                page.locator('#btnSubmit').click()
                page.wait_for_function("document.querySelector('#liveGameStatus').textContent.includes('Host')")
                assert f.state['revision'] == 1
                assert page.locator('#btnSubmit').is_disabled()
                assert len(f.receipts) == 1
                page.locator('#btnBack').click()
                assert ' of ' in page.locator('#snapshotPly').inner_text()
                assert page.locator('#snapshotPly').is_hidden()
                assert page.locator('#btnSubmit').is_disabled()
                f.verify()
                assert page.locator('#btnSubmit').is_disabled(), 'verification unlocked history'
                page.locator('#toggleNotation').click()
                assert page.locator('#toggleNotation').get_attribute('aria-expanded') == 'true'
                assert 'Copy FEN' in page.locator('#notationMount').inner_text()
                assert page.locator('.notation-readout').count() == 0
                page.evaluate("Object.defineProperty(navigator,'clipboard',{value:undefined,configurable:true}); document.execCommand=()=>false")
                page.get_by_role('button', name='Copy FEN', exact=True).click()
                assert page.locator('#notationMount .copy-note').first.inner_text() == 'Copy failed. Please try again.'
                assert page.locator('textarea').count() == 0, 'clipboard fallback leaked a focus target'
                assert page.get_by_role('button', name='Copy FEN', exact=True).evaluate('(e)=>e===document.activeElement')
                page.locator('#btnLive').click()
                assert page.locator('#historyNotice').count() == 0
                f.close()
                print('PASS keyboard, verification, cancellation, submit, history, notation', width, color)

        for outcome in ('lost', 'malformed', 'reject'):
            f = Fixture(browser)
            f.outcome = outcome
            f.select();f.verify();f.page.locator('#btnSubmit').click()
            wait_text(f.page, '#statusMsg', 'unconfirmed' if outcome != 'reject' else 'CAPTCHA verification failed')
            assert f.page.locator('#submissionHelp').is_hidden()
            assert f.page.locator('.status-line').get_attribute('aria-live') == 'assertive'
            assert f.page.locator('#errorBox').count() == 0
            if outcome != 'reject':
                assert f.page.locator('#btnCancel').is_visible()
                assert f.page.locator('#btnCancel').is_disabled()
                assert f.page.locator('#btnSubmit').is_enabled()
                first = f.posts[0]['submission_id']
                if outcome == 'lost':
                    f.page.locator('#btnRefresh').click()
                    wait_text(f.page, '#statusMsg', 'Move accepted')
                    assert f.state['revision'] == 1 and len(f.receipts) == 1
                else:
                    f.outcome = 'accept';f.verify();f.page.locator('#btnSubmit').click()
                    f.page.wait_for_function("document.querySelector('#liveGameStatus').textContent.includes('Host')")
                    assert f.state['revision'] == 1
                assert {post['submission_id'] for post in f.posts} == {first}
                assert f.page.evaluate("sessionStorage.getItem('chess-visitor-submission')") is None
            else:
                assert f.page.locator('#btnSubmit').is_disabled()
                f.verify()
                assert f.page.locator('#btnSubmit').is_enabled()
                assert f.state['revision'] == 0
            f.close();print('PASS submission recovery', outcome)

        f = Fixture(browser)
        f.select();f.verify()
        f.state.update(position('1. e4'));f.state['revision'] = 1
        f.page.evaluate('window.runPoll()')
        f.page.wait_for_function("document.querySelector('#updateBanner').classList.contains('show')")
        f.verify();assert f.page.locator('#btnSubmit').is_disabled()
        f.page.locator('#btnRefresh').click()
        assert f.page.locator('#cancelMoveDialog').is_visible()
        f.page.locator('#discardSelectedMove').click()
        assert f.page.locator('.selected-move').is_hidden()
        f.fail_state = True
        f.page.evaluate('window.runPoll()')
        f.page.wait_for_function("!document.querySelector('#connectionNotice').hidden")
        f.verify();assert f.page.locator('#btnSubmit').is_disabled()
        f.fail_state = False
        f.page.locator('#btnRefresh').click()
        f.page.wait_for_function("document.querySelector('#connectionNotice').hidden")
        f.close();print('PASS stale selection and connection failure/recovery')

        f = Fixture(browser)
        f.select();f.verify();f.fail_state = True
        f.page.locator('#btnSubmit').click()
        wait_text(f.page, '#statusMsg', 'Move accepted. Board refresh failed')
        assert f.state['revision'] == 1
        assert f.page.locator('#btnSubmit').is_disabled()
        assert f.page.locator('.selected-move').is_hidden()
        f.fail_state = False;f.page.locator('#btnRefresh').click()
        wait_text(f.page, '#liveGameStatus', 'Host')
        f.close();print('PASS committed move with failed refresh')

        f = Fixture(browser, color='black', pgn='1. f3 e5 2. g4')
        f.select('d8', 'h4');f.verify()
        assert f.page.locator('#gameOverBanner').is_hidden(), 'preview ended game before submit'
        with f.page.expect_response(lambda r: r.url.endswith('/api/state.php')):
            f.page.evaluate('window.runPoll()')
        f.page.evaluate('()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')
        assert f.page.locator('#statusMsg').inner_text() == ''
        assert f.page.locator('.selected-move').is_visible()
        assert f.page.locator('#gameOverBanner').is_hidden()
        assert f.page.locator('#btnSubmit').is_enabled()
        f.close();print('PASS mating preview remains unsubmitted across polling')

        f = Fixture(browser, color='black', pgn='1. e4')
        f.outcome = 'hold';f.select('e7', 'e5');f.verify()
        f.page.locator('#btnSubmit').click()
        f.page.wait_for_function("document.querySelector('#btnSubmit').textContent==='Submitting…'")
        f.page.locator('#btnSubmit').dispatch_event('click')
        f.page.locator('#btnBack').dispatch_event('click')
        assert len(f.posts) == 1
        assert ' of ' not in f.page.locator('#snapshotPly').inner_text()
        assert f.page.locator('#btnCancel').is_disabled()
        f.held[0].abort()
        wait_text(f.page, '#statusMsg', 'unconfirmed')
        f.close();print('PASS single in-flight submission and history guard')

        f = Fixture(browser)
        f.page.evaluate("delete window.turnstile; document.querySelector('#turnstileApi').dispatchEvent(new Event('error'))")
        f.select()
        assert f.page.locator('#btnSubmit').is_disabled()
        assert 'could not load' in f.page.locator('#submissionHelp').inner_text()
        assert not f.posts
        f.close();print('PASS blocked verification script fails closed with guidance')

        f = Fixture(browser)
        f.state['fen'] = 'invalid'
        f.page.locator('#btnRefresh').click()
        wait_text(f.page, '#statusMsg', 'Invalid canonical FEN')
        f.verify();assert f.page.locator('#btnSubmit').is_disabled()
        assert f.page.locator('#board .piece-img').count() == 0
        f.state.update(position())
        f.page.locator('#btnRefresh').click()
        f.page.wait_for_function("document.querySelectorAll('#board .piece-img').length===32")
        f.close();print('PASS invalid canonical FEN fails closed and refresh recovers')

        for color in ('white', 'black'):
            f = Fixture(browser, 320, color)
            fen = '4k3/P7/8/8/8/8/8/4K3 w - - 0 1' if color == 'white' else '4k3/8/8/8/8/8/p7/4K3 b - - 0 1'
            f.state.update(fen=fen, pgn=f'[SetUp "1"]\n[FEN "{fen}"]\n\n*', turn_color=color)
            f.page.reload();f.page.wait_for_function("document.body.dataset.gameId==='1'")
            f.select('a7' if color == 'white' else 'a2', 'a8' if color == 'white' else 'a1')
            assert f.page.locator('.promo-btn[data-piece=q]').evaluate('(e)=>e===document.activeElement')
            for piece in ('q', 'r', 'b', 'n'):
                button = f.page.locator(f'.promo-btn[data-piece={piece}]')
                button.focus();f.page.keyboard.press('Space')
                assert button.get_attribute('aria-pressed') == 'true'
            f.page.keyboard.press('Escape')
            assert f.page.locator('#promotionChooser').is_hidden()
            assert f.page.locator('.selected-move').is_hidden()
            assert f.square('a7' if color == 'white' else 'a2').locator('img').count() == 1
            f.select('a7' if color == 'white' else 'a2', 'a8' if color == 'white' else 'a1')
            f.state['revision'] += 1
            f.page.evaluate('window.runPoll()')
            wait_text(f.page, '#statusMsg', 'Game updated while choosing promotion')
            assert f.page.locator('#promotionChooser').is_hidden()
            assert f.page.locator('#btnSubmit').is_disabled()
            f.close();print('PASS promotion keyboard/cancel', color)

        for color in ('white', 'black'):
            f = Fixture(browser, color=color, pgn='1. f3 e5 2. g4 Qh4#')
            assert f.page.locator('#gameOverBanner').is_visible()
            # Black delivered mate; World owns black only in the second fixture.
            expected = 'Host' if color == 'white' else 'World'
            assert expected + ' wins' in f.page.locator('#gameOverBody').inner_text()
            f.verify();assert f.page.locator('#btnSubmit').is_disabled()
            f.close()
        print('PASS correct Host/World game-over copy')
        for pgn, source, target in [('1. e4 d5', 'e4', 'd5'), ('1. e4 a6 2. e5 d5', 'e5', 'd6')]:
            f = Fixture(browser, pgn=pgn)
            f.square(source).click()
            assert f.square(target).evaluate('(e)=>e.classList.contains("hint-capture")')
            assert 'capture' in f.square(target).get_attribute('aria-label')
            f.page.locator('#btnCancel').click()
            assert f.page.locator('#board .hint, #board .hint-capture').count() == 0
            assert not f.posts
            f.close()
        print('PASS capture and en-passant indicators clear with selection')
        browser.close()


if __name__ == '__main__':
    main()
