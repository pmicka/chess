#!/usr/bin/env python3
"""Preserve unsubmitted candidates until a destructive action is confirmed."""
import argparse
import sys
sys.dont_write_bytecode = True
from browser_interactions import Fixture, sync_playwright
from browser_ui_quality import audit


def main():
    args = argparse.ArgumentParser()
    args.add_argument('--axe-path')
    options = args.parse_args()
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        for width in (320, 390, 1280):
            f = Fixture(browser, width, pgn='1. e4 e5')
            page = f.page
            f.select('d2', 'd4'); f.verify()
            preview = page.locator('#movePreview').inner_text()
            page.locator('#toggleNotation').click()
            page.locator('#btnFlip').click()
            assert not page.locator('#cancelMoveDialog').is_visible()
            assert page.locator('#movePreview').inner_text() == preview
            f.square('g1').click()
            assert page.locator('#cancelMoveDialog').is_visible()
            assert page.locator('#keepSelectedMove').evaluate('(e)=>e===document.activeElement')
            assert page.locator('#movePreview').inner_text() == preview
            assert not f.posts
            page.keyboard.press('Tab')
            assert page.locator('#discardSelectedMove').evaluate('(e)=>e===document.activeElement')
            page.keyboard.press('Shift+Tab')
            assert page.locator('#keepSelectedMove').evaluate('(e)=>e===document.activeElement')
            if options.axe_path:
                result = audit(page, 'cancel confirmation', options.axe_path)
                assert not result['violations'], result
            page.keyboard.press('Escape')
            assert page.locator('#cancelMoveDialog').is_hidden()
            assert page.locator('#movePreview').inner_text() == preview
            assert page.locator('#btnSubmit').is_enabled()
            page.locator('#btnRefresh').click()
            assert page.locator('#cancelMoveDialog').is_visible()
            page.locator('#keepSelectedMove').click()
            assert page.locator('#movePreview').inner_text() == preview
            f.square('g1').click()
            page.locator('#discardSelectedMove').click()
            assert f.square('g1').evaluate('(e)=>e.classList.contains("pick")')
            f.select('d2', 'd4'); f.verify()
            page.locator('#btnBack').click()
            assert page.locator('#cancelMoveDialog').is_visible()
            page.locator('#discardSelectedMove').click()
            assert page.locator('#cancelMoveDialog').is_hidden()
            assert ' of ' in page.locator('#snapshotPly').inner_text()
            assert page.locator('#btnSubmit').is_disabled()
            assert page.locator('.move-preview-row').is_hidden()
            assert not f.posts
            page.locator('#btnLive').click()
            f.select('d2', 'd4'); f.verify()
            page.locator('#btnSubmit').click()
            page.wait_for_function("document.querySelector('#liveGameStatus').textContent.includes('Host')")
            assert len(f.posts) == 1
            assert page.locator('#cancelMoveDialog').is_hidden()
            f.close()
            print(f'PASS {width}px: board/refresh/history protection, Escape/keep, confirm/discard, safe controls, submission, modal accessibility', flush=True)
        browser.close()


if __name__ == '__main__':
    main()
