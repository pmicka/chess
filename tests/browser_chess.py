#!/usr/bin/env python3
"""Optional local browser regressions. Requires existing Playwright + Chromium.

PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
No external browser requests, real mail or live database. No screenshots with links.
"""
import json
import os
import re
import sys
sys.dont_write_bytecode = True

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('BLOCKED: optional browser checks require Playwright and Chromium.')
    raise SystemExit(77)

from host_authorization import HostAuthorization, START_FEN


def main():
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    passed = 0
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                headless=True, args=['--no-sandbox', '--disable-background-networking', '--disable-component-update'])
            try:
                for width, height in [(1280, 900), (390, 844)]:
                    for actor in ('host', 'visitor'):
                        for scenario in ('ordinary', 'promotion', 'engine_failure_retry', 'checkmate'):
                            fixture.setUp()
                            fen = '4k3/P7/8/8/8/8/8/4K3 w - - 0 1' if scenario == 'promotion' else START_FEN
                            pgn = f'[SetUp "1"]\n[FEN "{fen}"]\n\n*' if scenario == 'promotion' else ''
                            host, visitor = ('white','black') if actor == 'host' else ('black','white')
                            turn = 'white'
                            if scenario == 'checkmate':
                                fen = 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2'
                                pgn, turn = '1. f3 e5 2. g4', 'black'
                                host, visitor = visitor, host
                            fixture.execute('UPDATE games SET fen=?,pgn=?,host_color=?,visitor_color=?,turn_color=?', (fen,pgn,host,visitor,turn))
                            credential = fixture.token() if actor == 'host' else None
                            context = browser.new_context(viewport={'width':width, 'height':height})
                            def route_request(route):
                                if not route.request.url.startswith(HostAuthorization.base):
                                    route.abort(); return
                                if route.request.method == 'POST' and route.request.url.endswith(('api/visitor_move.php', 'api/my_move_submit.php')):
                                    payload = route.request.post_data_json
                                    payload['move' if actor == 'host' else 'last_move_san'] = '<script>falsified SAN</script>'
                                    route.continue_(post_data=json.dumps(payload)); return
                                route.continue_()
                            context.route('**/*', route_request)
                            page = context.new_page()
                            errors = []; page.on('pageerror', lambda e: errors.append(type(e).__name__))
                            path = 'my_move.php?token='+credential if actor == 'host' else 'index.php'
                            page.goto(HostAuthorization.base + path)
                            page.wait_for_function("document.querySelector('#board .sq') !== null")
                            if actor == 'host':
                                page.wait_for_function("document.querySelector('#statusMsg').textContent === 'Your turn.'")
                            else:
                                page.wait_for_function("document.body.dataset.gameId === '1'")
                            if scenario == 'promotion':
                                page.locator('#board .sq').nth(8).click(); page.locator('#board .sq').nth(0).click()
                                page.locator('#promotionChooser').wait_for(state='visible')
                                page.locator('.promo-btn[data-piece="r"]').click()
                            elif scenario == 'checkmate':
                                page.locator('#board .sq').nth(60).click(); page.locator('#board .sq').nth(24).click()
                            else:
                                page.locator('#board .sq').nth(52).click(); page.locator('#board .sq').nth(36).click()
                            if actor == 'visitor': page.evaluate("window.onTurnstileSuccess('synthetic')")
                            api = HostAuthorization.base + ('api/my_move_submit.php' if actor == 'host' else 'api/visitor_move.php')
                            if scenario == 'engine_failure_retry':
                                (fixture.app/'data/node-runtime').write_text('/nonexistent/synthetic-node')
                                before = fixture.snapshot()
                                with page.expect_response(lambda response: response.url == api and response.request.post_data_json.get('action','submit') == 'submit') as answer:
                                    page.locator('#btnSubmit').click()
                                assert answer.value.status == 503, 'engine failure must return 503'
                                page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('No move was saved')")
                                assert before == fixture.snapshot(), 'failed engine changed database rows'
                                assert len(fixture.mailbox()) == 0, 'failed engine sent email'
                                (fixture.app/'data/node-runtime').unlink()
                                if actor == 'visitor': page.evaluate("window.onTurnstileSuccess('synthetic')")
                            with page.expect_response(lambda response: response.url == api and response.request.post_data_json.get('action','submit') == 'submit') as answer:
                                page.locator('#btnSubmit').click()
                            assert answer.value.status == 200, 'retry did not return 200'
                            if scenario == 'checkmate':
                                if actor == 'host': page.locator('#btnNextGame').wait_for(state='visible')
                                else: page.wait_for_function("document.querySelector('#gameOverBanner').classList.contains('show')")
                            elif actor == 'host': page.wait_for_url('**/index.php')
                            else: page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('Waiting on host move')")
                            game = fixture.execute('SELECT pgn,last_move_san,revision FROM games')[0]
                            expected = 'Qh4#' if scenario == 'checkmate' else 'a8=R+' if scenario == 'promotion' else 'e4'
                            assert game['last_move_san'] == expected and game['revision'] == 1
                            assert 'falsified' not in game['pgn'] and '<script>' not in game['pgn']
                            if scenario == 'checkmate':
                                assert '[Result "0-1"]' in game['pgn'] and game['pgn'].endswith('Qh4# 0-1')
                                if actor == 'host':
                                    with page.expect_response(lambda response: response.url == HostAuthorization.base+'api/host_next_game.php') as transition:
                                        page.locator('#btnNextGame').click()
                                    assert transition.value.status == 200
                                    page.wait_for_url('**/index.php')
                                    assert len(fixture.execute('SELECT * FROM game_transitions')) == 1
                                assert len(fixture.mailbox()) == (2 if actor == 'host' else 1)
                            assert not errors
                            passed += 1
                            print(f'PASS {actor} {scenario} at {width}×{height}', flush=True)
                            context.close()
            finally: browser.close()
        print(f'{passed} browser checks passed.')
    finally: HostAuthorization.tearDownClass()


if __name__ == '__main__':
    try: main()
    except Exception as error:
        # Playwright errors can include URLs. Never print complete credentials.
        safe = re.sub(r'(?i)(token=)[A-Za-z0-9_%.-]+', r'\1[REDACTED]', str(error))
        print('FAIL browser check:', type(error).__name__, safe)
        raise SystemExit(1)
