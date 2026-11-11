#!/usr/bin/env python3
"""Reference sizing and copied notation-toggle contract. No outside requests.

Dimensions come from pmicka/notebook 7b92d91's PGN viewer. The live app keeps
its Live action and maps the reference toggle to displayed position details.
"""
import hashlib
import sys
sys.dont_write_bytecode = True
from browser_interactions import Fixture, ROOT, sync_playwright


def main():
    assert hashlib.sha256((ROOT / 'assets/icons/annot-pill.svg').read_bytes()).hexdigest() == '2ea3284d0d4e5b71da1ae297be2b8e60d7cff2c724d8d28c90851d8e6a8ed85b'
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        for width in (320, 390, 768, 1280):
            f = Fixture(browser, width, pgn='1. e4 e5 2. Nf3 Nc6')
            page = f.page
            page.evaluate("""() => {window.copied=[];Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>window.copied.push(text)}})}""")
            page.emulate_media(reduced_motion='no-preference')
            page.locator('.pgn-annot-toggle__icon').evaluate('(e)=>e.decode()')
            controls = page.locator('.history-controls button').evaluate_all('''els=>els.map(e=>{
              let r=e.getBoundingClientRect(),s=getComputedStyle(e);return {id:e.id,y:r.y,h:r.height,w:r.width,
                font:s.fontSize,padding:s.padding,lineHeight:s.lineHeight,radius:s.borderRadius}})''')
            mobile = width <= 520
            height = 44 if mobile else 40
            pill_width = 60 if mobile else 68
            assert len(controls) == 6
            assert max(e['y'] for e in controls) - min(e['y'] for e in controls) < 1
            assert all(e['h'] == height for e in controls)
            for e in controls[:4]:
                assert e['font'] == ('11px' if mobile else '13px')
                assert e['padding'] == ('0px 3px' if mobile else '0px 12px')
                assert e['radius'] == '8px'
            assert controls[-1]['w'] == pill_width and controls[-1]['radius'] == '999px'
            assert page.locator('#btnSubmit').bounding_box()['height'] == 40
            assert page.locator('#btnSubmit').bounding_box()['width'] < 150
            assert page.locator('.history-controls').evaluate('(e)=>e.scrollWidth<=e.clientWidth')
            assert page.locator('.history-buttons button').evaluate_all('(els)=>els.every(e=>e.scrollWidth<=e.clientWidth)')
            toggle = page.locator('#toggleNotation')
            knob = page.locator('.pgn-annot-toggle__knob')
            assert toggle.get_attribute('aria-pressed') == 'false'
            assert knob.bounding_box()['width'] == height - 4
            assert knob.evaluate('(e)=>getComputedStyle(e).left') == '0.5px'
            assert toggle.evaluate('(e)=>getComputedStyle(e).backgroundColor') == 'rgb(255, 107, 107)'
            toggle.focus();page.keyboard.press('Space')
            page.wait_for_function('(left)=>Math.abs(parseFloat(getComputedStyle(document.querySelector(".pgn-annot-toggle__knob")).left)-left)<.01', arg=pill_width - 2 - (height - 4))
            assert toggle.get_attribute('aria-pressed') == toggle.get_attribute('aria-expanded') == 'true'
            assert toggle.evaluate('(e)=>getComputedStyle(e).backgroundColor') == 'rgb(108, 214, 155)'
            assert page.locator('#notationMount').is_visible()
            assert page.evaluate("""() => {
              const before=(a,b)=>!!(document.querySelector(a).compareDocumentPosition(document.querySelector(b))&Node.DOCUMENT_POSITION_FOLLOWING);
              return before('.history-controls','.live-game-summary') && before('.live-game-summary','.action-row') && before('#notationMount','#scoreboard') && (innerWidth>=1024 ? before('.history-controls','#notationMount') && before('#notationMount','.live-game-summary') : before('.action-row','#notationMount'));
            }""")
            assert page.locator('.live-game-facts dd, .game-meta dd, .submission-help, .score-last').evaluate_all("els=>els.every(e=>getComputedStyle(e).fontSize==='14px'&&getComputedStyle(e).fontWeight==='400')")
            assert page.locator('.live-game-facts dt, .game-meta dt, .score-label').evaluate_all("els=>els.every(e=>getComputedStyle(e).fontSize==='12px'&&getComputedStyle(e).textTransform==='none')")
            if width >= 1024:
                boxes = [page.locator(selector).bounding_box() for selector in ('.board-container', '.history-controls', '#notationMount')]
                assert max(b['x'] for b in boxes)-min(b['x'] for b in boxes) < 1
                assert max(b['width'] for b in boxes)-min(b['width'] for b in boxes) < 1
                assert boxes[2]['y'] >= boxes[1]['y']+boxes[1]['height']
                page.locator('#copyFen').focus()
                page.set_viewport_size({'width':390, 'height':900})
                page.wait_for_function('document.querySelector("#notationMount").parentElement.classList.contains("control-bay")')
                assert page.locator('#copyFen').evaluate('(e)=>e===document.activeElement')
                page.set_viewport_size({'width':width, 'height':900})
                page.wait_for_function('document.querySelector("#notationMount").parentElement.classList.contains("board-stack")')
                assert toggle.get_attribute('aria-expanded') == 'true'
            assert page.locator('.notation-readout').count() == 0
            page.locator('#copyFen').click()
            assert page.evaluate('window.copied.at(-1)') == f.state['fen']
            page.locator('#copyPgn').click()
            assert page.evaluate('window.copied.at(-1)') == '1. e4 e5 2. Nf3 Nc6'
            assert page.locator('.pgn-annot-toggle__icon').count() == 1
            toggle.focus()
            page.keyboard.press('Space')
            assert toggle.get_attribute('aria-pressed') == toggle.get_attribute('aria-expanded') == 'false'
            assert page.locator('#notationMount').is_hidden()
            assert page.locator('#snapshotPly').is_hidden()
            page.locator('#btnBack').click()
            toggle.click()
            assert page.locator('#btnSubmit').is_disabled()
            assert page.locator('#historyStatus, #historyNotice').count() == 0
            assert page.locator('#snapshotPly').inner_text() == '3 of 4 ply · move 2'
            page.locator('#copyPgn').click()
            assert page.evaluate('window.copied.at(-1)') == '1. e4 e5 2. Nf3'
            page.locator('#copyFen').click()
            review_fen = page.evaluate('window.copied.at(-1)')
            assert review_fen != f.state['fen']
            page.locator('#btnStart').click()
            page.locator('#copyPgn').click()
            assert page.evaluate('window.copied.at(-1)') == ''
            page.locator('#btnLive').click()
            page.locator('#copyFen').click()
            assert page.evaluate('window.copied.at(-1)') == f.state['fen']
            f.select('d2', 'd4')
            page.locator('#copyPgn').click()
            assert page.evaluate('window.copied.at(-1)') == '1. e4 e5 2. Nf3 Nc6 3. d4'
            page.locator('#copyFen').click()
            assert page.evaluate('window.copied.at(-1)') != f.state['fen']
            page.locator('#btnCancel').click()
            page.locator('#copyFen').click()
            assert page.evaluate('window.copied.at(-1)') == f.state['fen']
            page.evaluate("Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{throw Error('denied')}}})")
            page.locator('#copyFen').click()
            assert 'Copy failed' in page.locator('#copyStatus').inner_text()
            assert page.locator('a[href="review.php"]').count() == 0
            assert not (ROOT / 'review.php').exists()
            assert not f.posts
            f.close()
            print(f'PASS {width}px: reference sizing, exact icon, toggle endpoints/colors, keyboard, displayed FEN/PGN, clipboard failure, history lock', flush=True)
        browser.close()


if __name__ == '__main__':
    main()
