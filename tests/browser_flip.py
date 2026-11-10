#!/usr/bin/env python3
"""Isolated public-board flip/promotion submissions; never contacts production."""
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

from host_authorization import HostAuthorization, START_FEN, ROOT
from browser_review import position, square


def expected_move(fen, source, target, promotion):
    script = """const {Chess}=require('./assets/chess.min.js');
      const game=new Chess(process.argv[1]);
      const move=game.move(JSON.parse(process.argv[2]));
      if(!move) process.exit(1);
      console.log(JSON.stringify({fen:game.fen(),san:move.san}));"""
    return json.loads(subprocess.check_output(['node', '-e', script, fen,
        json.dumps({'from': source, 'to': target, 'promotion': promotion or 'q'})], cwd=ROOT))


def main():
    after_e4 = position('1. e4')
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    passed = 0
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                headless=True, args=['--no-sandbox', '--disable-background-networking'])
            try:
                for width, height in [(1280, 900), (390, 844)]:
                    for color in ('white', 'black'):
                        for scenario in ('ordinary', 'q', 'r', 'b', 'n', 'interrupted'):
                            fixture.setUp()
                            white = color == 'white'
                            host = 'black' if white else 'white'
                            if scenario == 'ordinary':
                                fen = START_FEN if white else after_e4['fen']
                                pgn = '' if white else after_e4['pgn']
                                source, target, promotion = ('e2', 'e4', '') if white else ('e7', 'e5', '')
                            else:
                                fen = '4k3/P7/8/8/8/8/8/4K3 w - - 0 1' if white else '4k3/8/8/8/8/8/p7/4K3 b - - 0 1'
                                pgn = f'[SetUp "1"]\n[FEN "{fen}"]\n\n*'
                                source, target = ('a7', 'a8') if white else ('a2', 'a1')
                                promotion = 'q' if scenario == 'interrupted' else scenario
                            fixture.execute('UPDATE games SET fen=?,pgn=?,host_color=?,visitor_color=?,turn_color=?',
                                            (fen, pgn, host, color, color))
                            before = fixture.snapshot()
                            with browser.new_context(viewport={'width': width, 'height': height}) as context:
                                held, payloads, errors = [], [], []

                                def route_request(route):
                                    request = route.request
                                    if not request.url.startswith(fixture.base):
                                        route.abort()
                                    elif request.method == 'POST':
                                        if request.url != fixture.base + 'api/visitor_move.php':
                                            raise AssertionError('unexpected local mutation endpoint')
                                        payloads.append(request.post_data_json)
                                        held.append(route)
                                    else:
                                        route.continue_()

                                context.route('**/*', route_request)
                                page = context.new_page()
                                page.on('pageerror', lambda error: errors.append(type(error).__name__))
                                page.goto(fixture.base + 'index.php')
                                page.wait_for_function("document.body.dataset.gameId === '1'")
                                page.locator('#btnFlip').click()
                                square(page, source, color).click()
                                assert 'pick' in square(page, source, color).get_attribute('class')
                                assert 'hint' in square(page, target, color).get_attribute('class')
                                # Flip during selection, then make the same logical move.
                                page.locator('#btnFlip').click()
                                assert 'pick' in square(page, source, color).get_attribute('class')
                                square(page, target, color).click()
                                preview = page.locator('#movePreview').inner_text()
                                enabled = page.locator('#btnSubmit').is_enabled()
                                # Flip during the pending preview/promotion chooser.
                                page.locator('#btnFlip').click()
                                assert page.locator('#movePreview').inner_text() == preview
                                assert page.locator('#btnSubmit').is_enabled() == enabled
                                if promotion:
                                    assert page.locator('#promotionChooser').is_visible()
                                    page.locator(f'.promo-btn[data-piece="{promotion}"]').click()
                                    image = page.locator(f'.promo-btn[data-piece="{promotion}"] img')
                                    assert image.get_attribute('src').endswith(('w' if white else 'b') + promotion.upper() + '.svg')
                                    page.locator('#btnFlip').click()
                                    page.locator('#btnFlip').click()
                                    assert page.locator('#promotionChooser').is_visible()
                                assert fixture.snapshot() == before and not fixture.mailbox()
                                assert page.locator('#visitorColorLabel').inner_text() == color
                                assert page.locator('#hostColorLabel').inner_text() == host

                                if scenario == 'interrupted':
                                    fixture.execute('UPDATE games SET revision=1')
                                    changed = fixture.snapshot()
                                    page.locator('#btnRefresh').click()
                                    page.wait_for_function("!document.querySelector('#promotionChooser').classList.contains('show')")
                                    assert page.locator('#btnSubmit').is_disabled()
                                    assert square(page, source, color).locator('img').get_attribute('src').endswith(('w' if white else 'b') + 'P.svg')
                                    assert fixture.snapshot() == changed and not payloads
                                else:
                                    expected = expected_move(fen, source, target, promotion)
                                    # Flipping cannot satisfy the CAPTCHA requirement.
                                    assert page.locator('#btnSubmit').is_disabled()
                                    assert not payloads and fixture.snapshot() == before
                                    page.locator('#btnFlip').click()
                                    page.locator('#btnFlip').click()
                                    assert page.locator('#btnSubmit').is_disabled()
                                    page.evaluate("window.onTurnstileSuccess('synthetic')")
                                    page.locator('#btnSubmit').click()
                                    page.wait_for_function("document.querySelector('#btnFlip').disabled")
                                    assert len(held) == 1
                                    assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'true'
                                    payload = payloads[0]
                                    assert (payload['from'], payload['to'], payload['promotion']) == (source, target, promotion)
                                    assert payload['game_id'] == 1 and payload['expected_revision'] == 0
                                    assert 'fen' not in payload and 'pgn' not in payload
                                    with page.expect_response(lambda response: response.url == fixture.base + 'api/visitor_move.php') as answer:
                                        held[0].continue_()
                                    assert answer.value.status == 200
                                    page.wait_for_function("!document.querySelector('#btnFlip').disabled")
                                    game = fixture.execute('SELECT fen,last_move_san,revision,host_color,visitor_color FROM games')[0]
                                    assert game == dict(fen=expected['fen'], last_move_san=expected['san'], revision=1,
                                                        host_color=host, visitor_color=color)
                                    assert len(fixture.execute('SELECT * FROM move_receipts')) == 1
                                    assert len(fixture.mailbox()) == 1
                                    assert page.locator('#btnFlip').get_attribute('aria-pressed') == 'true'
                                assert not errors
                            passed += 1
                            print(f'PASS flip {color} {scenario} at {width}×{height}', flush=True)
            finally:
                browser.close()
        print(f'{passed} flip browser scenarios passed.')
    finally:
        HostAuthorization.tearDownClass()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        import traceback
        frames = [frame for frame in traceback.extract_tb(error.__traceback__) if frame.filename == __file__]
        line = frames[-1].lineno if frames else 0
        print(f'FAIL flip browser check: {type(error).__name__} at line {line}')
        raise SystemExit(1)
