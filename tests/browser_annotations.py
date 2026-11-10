#!/usr/bin/env python3
"""Browser-only imports in temporary fixtures; reject all API/external/write requests."""
import os
from pathlib import Path
import shutil
import sys
sys.dont_write_bytecode = True
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('BLOCKED: Playwright and Chromium required.')
    raise SystemExit(77)
from host_authorization import HostAuthorization, ROOT

SAMPLE = '''[White "<img src=x onerror=alert(1)>"]
[Black "Black"]

1.e4?! { (0.13 → -0.79) Inaccuracy. d4 was best. <script>alert(1)</script> }
(1.d4 {Blunder.} d5) e5 2.Nf3 $1 {[%eval 0.3]} Nc6 *'''


def main():
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    # The standard fixture copies tracked files; include this phase's new entrypoint/CSS before commit.
    for name in ('review.php', 'assets/css/annotated-review.css'):
        target = fixture.app / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    count = 0
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                headless=True, args=['--no-sandbox', '--disable-background-networking'])
            try:
                for width, height in ((1280, 900), (390, 844), (320, 740)):
                    fixture.setUp()
                    before = fixture.snapshot()
                    with browser.new_context(viewport={'width': width, 'height': height}) as context:
                        forbidden, errors = [], []
                        def route_request(route):
                            request = route.request
                            if not request.url.startswith(fixture.base) or '/api/' in request.url or request.method != 'GET':
                                forbidden.append(request.method); route.abort()
                            else: route.continue_()
                        context.route('**/*', route_request)
                        page = context.new_page()
                        page.on('pageerror', lambda error: errors.append(type(error).__name__))
                        page.goto(fixture.base + 'review.php')
                        page.evaluate("localStorage.setItem('live-marker','unchanged');sessionStorage.setItem('chess_viewMode','live')")
                        storage = page.evaluate('JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)])')
                        page.locator('#pgnInput').fill(SAMPLE)
                        page.get_by_role('button', name='Review PGN', exact=True).click()
                        page.locator('#importedReview').wait_for(state='visible')
                        assert page.locator('#importedPlayers').inner_text().startswith('<img')
                        assert page.locator('#importedPlayers img, #annotationComment script').count() == 0
                        assert page.locator('#reviewPosition').inner_text().startswith('Ply 0 of 4')
                        page.locator('#reviewNext').click()
                        assert 'Supplied classification: Inaccuracy' in page.locator('#annotationSummary').inner_text()
                        assert 'Supplied suggestion before this move: d4' in page.locator('#annotationSummary').inner_text()
                        assert page.locator('#suggestionArrow line').count() == 1
                        assert page.locator('.supplied-move-badge').inner_text() == '?!'
                        assert '<script>' in page.locator('#annotationComment').inner_text()
                        line = page.locator('#suggestionArrow line')
                        original = [line.get_attribute(key) for key in ('x1', 'y1', 'x2', 'y2')]
                        page.locator('#reviewFlip').click()
                        flipped = [page.locator('#suggestionArrow line').get_attribute(key) for key in ('x1', 'y1', 'x2', 'y2')]
                        assert [float(a) + float(b) for a, b in zip(original, flipped)] == [100] * 4
                        page.set_viewport_size({'width': max(320, width - 30), 'height': height})
                        assert [page.locator('#suggestionArrow line').get_attribute(key) for key in ('x1', 'y1', 'x2', 'y2')] == flipped
                        page.locator('#annotationToggle').click()
                        assert page.locator('#annotationPanel').is_hidden()
                        assert page.locator('#suggestionArrow line').count() == 0
                        assert page.locator('.supplied-move-badge').count() == 0
                        page.locator('#annotationToggle').focus(); page.keyboard.press('Space')
                        assert page.locator('#annotationPanel').is_visible()
                        page.keyboard.press('End')
                        assert page.locator('#reviewPosition').inner_text().startswith('Ply 4 of 4')
                        assert 'No structured annotation' in page.locator('#annotationSummary').inner_text()
                        assert page.locator('#suggestionArrow line').count() == 0
                        final_fen = page.locator('#reviewFen').inner_text()
                        page.locator('#pgnInput').fill('1.e4 {broken')
                        page.get_by_role('button', name='Review PGN', exact=True).click()
                        assert 'previously loaded review' in page.locator('#importError').inner_text()
                        assert page.locator('#reviewFen').inner_text() == final_fen
                        page.locator('#pgnInput').fill('1.e4 e5')
                        page.get_by_role('button', name='Review PGN', exact=True).click()
                        page.locator('#reviewFinal').click()
                        assert 'No structured annotation' in page.locator('#annotationSummary').inner_text()
                        assert page.locator('#suggestionArrow line').count() == 0
                        for button in page.locator('.review-actions button, #annotationToggle').all():
                            rect = button.bounding_box()
                            assert rect['width'] >= 44 and rect['height'] >= 44
                            assert button.evaluate('(el)=>el.scrollWidth<=el.clientWidth')
                        assert page.evaluate('document.documentElement.scrollWidth<=window.innerWidth')
                        assert page.evaluate('JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)])') == storage
                        page.locator('#clearReview').click()
                        assert page.locator('#pgnInput').input_value() == ''
                        assert page.locator('#importedReview').is_hidden()
                        assert page.locator('#reviewSource').inner_text() == ''
                        assert page.locator('#pgnInput').evaluate('(el)=>el===document.activeElement')
                        assert fixture.snapshot() == before and not fixture.mailbox()
                        assert not forbidden and not errors
                    count += 1
                    print(f'PASS local annotated review at {width}×{height}', flush=True)
            finally: browser.close()
        print(f'{count} annotated review browser scenarios passed.')
    finally: HostAuthorization.tearDownClass()


if __name__ == '__main__':
    try: main()
    except Exception as error:
        import traceback
        frames = [f for f in traceback.extract_tb(error.__traceback__) if f.filename == __file__]
        print(f'FAIL annotation browser check: {type(error).__name__} at line {frames[-1].lineno if frames else 0}')
        raise SystemExit(1)
