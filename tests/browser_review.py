#!/usr/bin/env python3
"""Phase 0 review baseline, using only the temporary HostAuthorization fixture.

PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_review.py
No production URL, external browser requests, real mail, or submitted moves.
"""
import json
import os
import subprocess
import sys

sys.dont_write_bytecode = True
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('BLOCKED: optional browser checks require Playwright and Chromium.')
    raise SystemExit(77)

from host_authorization import HostAuthorization, ROOT, START_FEN


def position(pgn):
    script = """const {Chess}=require('./assets/chess.min.js');
      const game=new Chess();
      if(!game.load_pgn(process.argv[1])) process.exit(1);
      console.log(JSON.stringify({fen:game.fen(), pgn:game.pgn()}));"""
    return json.loads(subprocess.check_output(['node', '-e', script, pgn], cwd=ROOT))


def square(page, logical, color):
    flip = page.locator('#btnFlip')
    if flip.count() and flip.get_attribute('aria-pressed') == 'true':
        color = 'black' if color == 'white' else 'white'
    files = 'abcdefgh' if color == 'white' else 'hgfedcba'
    ranks = '87654321' if color == 'white' else '12345678'
    return page.locator('#board .sq').nth(ranks.index(logical[1]) * 8 + files.index(logical[0]))


def wait_history(page, text):
    import re
    match = re.fullmatch(r'Reviewing ply (\d+) of (\d+)', text)
    if match:
        expected = f'{match[1]} of {match[2]} ply · move '
        page.wait_for_function('(text)=>document.querySelector("#snapshotPly").textContent.startsWith(text)', arg=expected)
    else:
        page.wait_for_function('()=>!document.querySelector("#snapshotPly").textContent.includes(" of ")')


def main():
    initial = position('1. e4 e5 2. Nf3 Nc6 3. Bb5 a6')
    advanced = position('1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 4. Ba4')
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    passed = 0
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                headless=True, args=['--no-sandbox', '--disable-background-networking'])
            try:
                for width, height in [(1280, 900), (390, 844), (320, 740)]:
                    for color in ('white', 'black'):
                        fixture.setUp()
                        host = 'black' if color == 'white' else 'white'
                        fixture.execute("UPDATE games SET fen=?,pgn=?,host_color=?,visitor_color=?,last_move_san='a6',updated_at='2026-11-01 12:34:56'",
                                        (initial['fen'], initial['pgn'], host, color))
                        before = fixture.snapshot()
                        with browser.new_context(viewport={'width': width, 'height': height},
                                                 timezone_id='Pacific/Honolulu', locale='en-US') as context:
                            posts, errors = [], []

                            def route_request(route):
                                if not route.request.url.startswith(fixture.base):
                                    route.abort()
                                elif route.request.method != 'GET':
                                    posts.append(route.request.method)
                                    route.abort()
                                else:
                                    route.continue_()

                            context.route('**/*', route_request)
                            # Expose the real poll callback so synchronization is tested without a 20s sleep.
                            context.add_init_script('''const originalInterval=window.setInterval;
                              window.setInterval=(callback,ms,...args)=>{
                                if(ms===20000) window.phase0Poll=callback;
                                return originalInterval(callback,ms,...args);
                              };''')
                            page = context.new_page()
                            page.on('pageerror', lambda error: errors.append(type(error).__name__))
                            page.goto(fixture.base + 'index.php')
                            page.wait_for_function("document.body.dataset.gameId === '1' && typeof window.phase0Poll === 'function'")
                            wait_history(page, 'Live')
                            assert page.locator('#liveGameId').inner_text() == '#1'
                            assert page.locator('#hostColorLabel').inner_text() == host
                            assert page.locator('#visitorColorLabel').inner_text() == color
                            assert page.locator('#liveGameStatus').inner_text() == ('Your turn' if color == 'white' else 'Host to move')
                            assert page.locator('#liveLastMove').inner_text() == 'a6'
                            assert page.locator('#liveUpdatedAt').get_attribute('datetime') == '2026-11-01T12:34:56Z'
                            assert '2:34 AM' in page.locator('#liveUpdatedAt').inner_text()
                            assert '2:34 AM' in page.locator('#snapshotLastMove').inner_text()
                            assert [page.locator(selector).inner_text() for selector in ('#scoreHostValue', '#scoreWorldValue', '#scoreDrawValue')] == ['3', '2', '1']
                            for selector in ('.live-game-card', '.live-game-facts', '.score-grid'):
                                assert page.locator(selector).evaluate('(el) => el.scrollWidth <= el.clientWidth')
                            assert page.locator('.live-game-card').evaluate('(el) => el.getBoundingClientRect().top >= document.querySelector("#btnSubmit").getBoundingClientRect().bottom')
                            ids = page.locator('#board .sq').evaluate_all('(els) => els.map(el => el.dataset.squareId)')
                            page.locator('#btnFlip').click()
                            assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'true'
                            assert page.locator('#board .sq').evaluate_all('(els) => els.map(el => el.dataset.squareId)') == ids[::-1]
                            assert page.locator('#visitorColorLabel').inner_text() == color
                            assert page.locator('#hostColorLabel').inner_text() == host
                            assert page.locator('#btnFlip').evaluate('(el) => el === document.activeElement')
                            assert page.locator('#btnStart').is_visible()
                            assert page.locator('#btnStart').is_enabled()
                            for button in page.locator('.history-buttons button, #btnFlip').all():
                                rect = button.bounding_box()
                                assert rect['x'] >= 0 and rect['x'] + rect['width'] <= width
                                if width <= 768:
                                    assert rect['width'] >= 44 and rect['height'] >= 44
                                assert button.evaluate('(el) => el.scrollWidth <= el.clientWidth')
                            assert page.locator('#board .sq').count() == 64
                            assert square(page, 'a6', color).locator('.piece-img, .piece-svg').count() == 1
                            assert square(page, 'a7', color).locator('.piece-img, .piece-svg').count() == 0
                            assert 'last' in square(page, 'a6', color).get_attribute('class')
                            assert 'last' in square(page, 'a7', color).get_attribute('class')
                            bounds = page.locator('#board').bounding_box()
                            assert abs(bounds['width'] - bounds['height']) < 1
                            assert bounds['x'] >= 0 and bounds['x'] + bounds['width'] <= width

                            if color == 'white':
                                square(page, 'd2', color).click()
                                assert 'pick' in square(page, 'd2', color).get_attribute('class')
                                assert 'hint' in square(page, 'd4', color).get_attribute('class')
                                page.locator('#btnFlip').click()
                                assert 'pick' in square(page, 'd2', color).get_attribute('class')
                                assert 'hint' in square(page, 'd4', color).get_attribute('class')
                                square(page, 'd4', color).click()
                                preview = page.locator('#movePreview').inner_text()
                                page.locator('#btnFlip').click()
                                assert page.locator('#movePreview').inner_text() == preview
                                assert square(page, 'd4', color).locator('.piece-img, .piece-svg').count() == 1
                                assert fixture.snapshot() == before, 'local preview changed persisted rows'
                                page.locator('#btnStart').click()
                                wait_history(page, 'Reviewing ply 0 of 6')
                                assert page.locator('#btnSubmit').is_disabled()
                                assert page.locator('#board .pick, #board .hint').count() == 0
                                page.locator('#btnLive').click()
                                wait_history(page, 'Live')
                                assert square(page, 'd2', color).locator('.piece-img, .piece-svg').count() == 1
                                assert square(page, 'd4', color).locator('.piece-img, .piece-svg').count() == 0
                            else:
                                # It is White's turn: Black cannot select its own piece.
                                square(page, 'g8', color).evaluate('(element) => element.click()')
                                assert page.locator('#board .pick').count() == 0

                            page.locator('#btnBack').click()
                            wait_history(page, 'Reviewing ply 5 of 6')
                            assert page.locator('#snapshotLastMove').inner_text() == 'Bb5'
                            assert page.locator('#liveLastMove').inner_text() == 'a6'
                            page.locator('#btnFlip').focus()
                            page.keyboard.press('Space')
                            wait_history(page, 'Reviewing ply 5 of 6')
                            assert square(page, 'a7', color).locator('.piece-img, .piece-svg').count() == 1
                            assert page.locator('#btnSubmit').is_disabled()
                            # Bypass the CSS pointer lock to exercise the JavaScript review guard too.
                            square(page, 'b5', color).evaluate('(element) => element.click()')
                            assert page.locator('#board .pick').count() == 0
                            page.keyboard.press('Home')
                            wait_history(page, 'Reviewing ply 0 of 6')
                            assert page.locator('#btnStart').is_disabled()
                            assert page.locator('#btnBack').is_disabled()
                            assert page.locator('#board .piece-img, #board .piece-svg').count() == 32
                            page.keyboard.press('ArrowRight')
                            wait_history(page, 'Reviewing ply 1 of 6')
                            assert square(page, 'e4', color).locator('.piece-img, .piece-svg').count() == 1
                            page.reload()
                            page.wait_for_function("document.body.dataset.gameId === '1'")
                            assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'false'
                            wait_history(page, 'Reviewing ply 1 of 6')
                            page.locator('#btnStart').focus()
                            page.keyboard.press('Enter')
                            wait_history(page, 'Reviewing ply 0 of 6')
                            assert page.locator('#btnStart').is_disabled()
                            assert page.locator('#btnSubmit').is_disabled()
                            assert page.locator('#board .piece-img, #board .piece-svg').count() == 32
                            page.keyboard.press('End')
                            wait_history(page, 'Live')
                            page.locator('#btnStart').click()
                            wait_history(page, 'Reviewing ply 0 of 6')
                            assert page.locator('#btnSubmit').is_disabled()
                            page.locator('#btnLive').click()
                            wait_history(page, 'Live')
                            page.locator('#btnBack').click()
                            wait_history(page, 'Reviewing ply 5 of 6')
                            page.locator('#btnForward').click()
                            wait_history(page, 'Live')
                            assert fixture.snapshot() == before, 'review controls changed persisted rows'
                            assert not fixture.mailbox(), 'review controls sent mail'

                            # Only the fixture changes server state; the browser remains read-only.
                            page.locator('#btnBack').click()
                            wait_history(page, 'Reviewing ply 5 of 6')
                            fixture.execute("UPDATE games SET fen=?,pgn=?,turn_color='black',revision=1,last_move_san='Ba4',updated_at='2026-11-07 12:00:00'",
                                            (advanced['fen'], advanced['pgn']))
                            after_update = fixture.snapshot()
                            page.evaluate('window.phase0Poll()')
                            page.wait_for_function("document.querySelector('#updateBanner').classList.contains('show')")
                            assert page.locator('#liveLastMove').inner_text() == 'Ba4'
                            assert page.locator('#liveGameStatus').inner_text() == ('Your turn' if color == 'black' else 'Host to move')
                            assert page.locator('#liveUpdatedAt').get_attribute('datetime') == '2026-11-07T12:00:00Z'
                            assert page.locator('#snapshotLastMove').inner_text() == 'Bb5'
                            wait_history(page, 'Reviewing ply 5 of 6')
                            page.locator('#btnFlip').click()
                            assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'true'
                            assert square(page, 'b5', color).locator('.piece-img, .piece-svg').count() == 1
                            assert page.locator('#btnSubmit').is_disabled()
                            page.locator('#btnStart').click()
                            wait_history(page, 'Reviewing ply 0 of 6')
                            assert page.locator('#btnSubmit').is_disabled()
                            assert fixture.snapshot() == after_update
                            page.locator('#btnLive').click()
                            wait_history(page, 'Live')
                            assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'true'
                            assert square(page, 'a4', color).locator('.piece-img, .piece-svg').count() == 1
                            assert square(page, 'b5', color).locator('.piece-img, .piece-svg').count() == 0
                            assert fixture.snapshot() == after_update, 'poll/Live changed persisted rows'

                            # Divergent history must not replace the canonical live FEN.
                            fixture.execute("UPDATE games SET pgn='1. e4',revision=2")
                            after_divergence = fixture.snapshot()
                            with page.expect_response(lambda response: response.url.endswith('/api/state.php')):
                                page.locator('#btnRefresh').click()
                            page.wait_for_function("document.querySelector('#btnBack').disabled")
                            assert page.locator('#btnStart').is_disabled()
                            assert square(page, 'a4', color).locator('.piece-img, .piece-svg').count() == 1
                            assert fixture.snapshot() == after_divergence, 'history fallback rewrote game state'

                            fixture.execute("UPDATE games SET fen=?,pgn='',last_move_san=NULL,turn_color='white',revision=3", (START_FEN,))
                            empty_snapshot = fixture.snapshot()
                            with page.expect_response(lambda response: response.url.endswith('/api/state.php')):
                                page.locator('#btnRefresh').click()
                            page.wait_for_function("document.querySelectorAll('#board .piece-img').length === 32")
                            for selector in ('#btnStart', '#btnBack', '#btnForward', '#btnLive'):
                                assert page.locator(selector).is_disabled()
                            assert fixture.snapshot() == empty_snapshot, 'empty-history navigation changed state'

                            # Simulate a server-owned successor, without a next-game API call.
                            fixture.execute("UPDATE games SET status='finished'")
                            fixture.execute("INSERT INTO games (id,host_color,visitor_color,turn_color,status,fen,pgn) VALUES (2,?,?,'white','active',?,'')",
                                            (color, host, START_FEN))
                            successor = fixture.snapshot()
                            page.locator('#btnRefresh').click()
                            page.wait_for_function("document.body.dataset.gameId === '2'")
                            assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'false'
                            assert page.locator('#visitorColorLabel').inner_text() == host
                            assert page.locator('#liveGameId').inner_text() == '#2'
                            assert page.locator('#visitorColorLabel').inner_text() == host
                            assert page.locator('#liveLastMove').inner_text() == '—'
                            assert square(page, 'e1', host).locator('img').get_attribute('src').endswith('wK.svg')
                            assert fixture.snapshot() == successor
                            assert not fixture.mailbox() and not posts and not errors
                        passed += 1
                        print(f'PASS review, coordinates and queued poll: {color} at {width}×{height}', flush=True)
            finally:
                browser.close()
        print(f'{passed} review baseline scenarios passed.')
    finally:
        HostAuthorization.tearDownClass()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Do not expose browser request URLs/bodies or fixture credentials.
        import traceback
        frames = [frame for frame in traceback.extract_tb(error.__traceback__) if frame.filename == __file__]
        line = frames[-1].lineno if frames else 0
        print(f'FAIL review baseline: {type(error).__name__} at line {line}')
        raise SystemExit(1)
