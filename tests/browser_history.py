#!/usr/bin/env python3
"""Historical highlights/status in disposable fixtures; all browser writes blocked."""
import json
import argparse
import os
import subprocess
import sys
sys.dont_write_bytecode = True
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('BLOCKED: Playwright and Chromium required.')
    raise SystemExit(77)
from host_authorization import HostAuthorization, ROOT
from browser_review import square, wait_history


def history(pgn):
    script = """const {Chess}=require('./assets/chess.min.js');const c=new Chess();
      if(!c.load_pgn(process.argv[1])) process.exit(1);
      const moves=c.history();const replay=new Chess();const frames=[];
      function frame(move=null){
        const king='abcdefgh'.split('').flatMap(f=>[1,2,3,4,5,6,7,8].map(r=>f+r))
          .find(sq=>{const p=replay.get(sq);return p&&p.type==='k'&&p.color===replay.turn();});
        frames.push({move,king,check:replay.in_check(),mate:replay.in_checkmate(),stalemate:replay.in_stalemate()});}
      frame();for(const san of moves){frame(replay.move(san));}
      console.log(JSON.stringify({fen:c.fen(),pgn:c.pgn(),frames}));"""
    return json.loads(subprocess.check_output(['node', '-e', script, pgn], cwd=ROOT))


def assert_position(page, frame, color):
    move = frame['move']
    expected = set()
    if move:
        expected = {square(page, move[key], color).get_attribute('data-square-id') for key in ('from', 'to')}
    actual = set(page.locator('#board .last').evaluate_all('(els)=>els.map(el=>el.dataset.squareId)'))
    assert actual == expected, 'wrong historical move endpoints'
    assert page.locator('#snapshotLastMove').inner_text() == (move['san'] if move else '—')
    status = page.locator('#snapshotStatus').inner_text()
    assert status.startswith('Review position'), 'historical status must be distinct from live result'
    assert ('checkmate' in status) == frame['mate']
    assert ('check' in status) == frame['check']
    assert ('stalemate' in status) == frame['stalemate']
    assert page.locator('#board .review-check').count() == int(frame['check'])
    if frame['check']:
        assert page.locator('#board .review-check').get_attribute('data-square-id') == square(page, frame['king'], color).get_attribute('data-square-id')
        assert page.locator('#board .review-check img').get_attribute('src').endswith('K.svg')
    assert page.locator('#btnSubmit').is_disabled()


def main():
    cases = {
        'castling': '1. e4 e5 2. Nf3 Nc6 3. Bc4 Nf6 4. O-O Be7',
        'en-passant': '1. e4 a6 2. e5 d5 3. exd6 cxd6',
        'promotions': '1. a4 h5 2. a5 h4 3. a6 h3 4. axb7 hxg2 5. bxa8=Q gxh1=Q',
        'mate': '1. f3 e5 2. g4 Qh4#',
        'check': '1. e4 e5 2. Bb5 Nc6 3. Bxc6 dxc6 4. Qh5 Nf6 5. Qxe5+ Be7',
        'stalemate': '1. e3 a5 2. Qh5 Ra6 3. Qxa5 h5 4. Qxc7 Rah6 5. h4 f6 6. Qxd7+ Kf7 7. Qxb7 Qd3 8. Qxb8 Qh7 9. Qxc8 Kg6 10. Qe6',
    }
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--case', choices=tuple(cases), help='Run one history fixture')
    args = parser.parse_args()
    if args.case:
        cases = {args.case: cases[args.case]}
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    passed = 0
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                headless=True, args=['--no-sandbox', '--disable-background-networking'])
            try:
                for width, height in ((1280, 900), (390, 844)):
                    for color in ('white', 'black'):
                        for name, pgn in cases.items():
                            fixture.setUp()
                            data = history(pgn)
                            turn = 'white' if data['fen'].split()[1] == 'w' else 'black'
                            fixture.execute('UPDATE games SET fen=?,pgn=?,turn_color=?,visitor_color=?,host_color=?,last_move_san=?',
                                (data['fen'], data['pgn'], turn, color, 'black' if color == 'white' else 'white', 'Canonical latest'))
                            before = fixture.snapshot()
                            with browser.new_context(viewport={'width': width, 'height': height}) as context:
                                writes, errors = [], []
                                def route_request(route):
                                    if not route.request.url.startswith(fixture.base):
                                        route.abort()
                                    elif route.request.method != 'GET':
                                        writes.append(route.request.method); route.abort()
                                    else:
                                        route.continue_()
                                context.route('**/*', route_request)
                                page = context.new_page()
                                page.on('pageerror', lambda error: errors.append(type(error).__name__))
                                page.goto(fixture.base + 'index.php')
                                page.wait_for_function("document.body.dataset.gameId === '1'")
                                total = len(data['frames']) - 1
                                # Restore an explicitly reviewed final ply, including a checkmate position.
                                page.evaluate('''(ply)=>{
                                  sessionStorage.setItem('chess_viewMode','review');
                                  sessionStorage.setItem('chess_selectedPly',String(ply));
                                  sessionStorage.setItem('chess_viewStateTs',String(Date.now()));
                                }''', total)
                                page.reload()
                                for ply in range(total, -1, -1):
                                    wait_history(page, f'Move {ply} of {total}')
                                    assert_position(page, data['frames'][ply], color)
                                    page.locator('#btnFlip').click()
                                    assert_position(page, data['frames'][ply], color)
                                    if ply:
                                        page.locator('#btnBack').click()
                                assert page.locator('#board .last, #board .review-check').count() == 0
                                page.locator('#btnLive').click()
                                wait_history(page, 'Live')
                                assert page.locator('#snapshotLastMove').inner_text().startswith('Canonical latest')
                                assert page.locator('#board .review-check').count() == 0
                                assert fixture.snapshot() == before and not fixture.mailbox()
                                assert not writes and not errors
                            passed += 1
                            print(f'PASS history {name} {color} at {width}×{height}', flush=True)
            finally:
                browser.close()
        print(f'{passed} historical visualization scenarios passed.')
    finally:
        HostAuthorization.tearDownClass()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        import traceback
        frames = [f for f in traceback.extract_tb(error.__traceback__) if f.filename == __file__]
        print(f'FAIL history browser check: {type(error).__name__} at line {frames[-1].lineno if frames else 0}')
        raise SystemExit(1)
