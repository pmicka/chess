#!/usr/bin/env python3
"""Isolated move coaching, reminder timing, and review-lock feedback."""
import sys
sys.dont_write_bytecode = True
from browser_interactions import Fixture, sync_playwright


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        for width in (390, 1280):
            f = Fixture(browser, width)
            page = f.page
            page.clock.install()
            assert page.locator('#liveGameStatus').inner_text() == 'Visitor Turn'
            assert page.locator('.game-watermark #liveGameId').inner_text() == '#1'
            assert page.locator('#liveGameTitle').count() == 0
            f.square('e2').click()
            assert 'highlighted destination' in page.locator('#submissionHelp').inner_text()
            f.square('e5').click()
            assert page.locator('#moveGuide #statusMsg').is_visible()
            assert 'not legal' in page.locator('#statusMsg').inner_text()
            assert page.locator('#submissionHelp').is_hidden()
            assert page.locator('#moveGuide').evaluate('(e)=>e.closest(".control-bay")!==null')
            assert page.locator('#moveGuide').evaluate('(e)=>getComputedStyle(e,"::before").content') == 'none'
            f.square('e4').click()
            assert page.locator('#statusMsg').inner_text() == ''
            assert 'Checking security' in page.locator('#submissionHelp').inner_text()
            assert page.locator('#btnSubmit').is_disabled()
            assert page.locator('#btnSubmit').get_attribute('data-phase') == 'verification'
            page.clock.run_for(29999)
            assert 'still waiting' not in page.locator('#submissionHelp').inner_text()
            page.clock.run_for(1)
            assert 'not submitted' in page.locator('#submissionHelp').inner_text()
            f.verify()
            assert 'still waiting' in page.locator('#submissionHelp').inner_text()
            assert page.locator('#btnSubmit').get_attribute('data-phase') == 'ready'
            page.emulate_media(reduced_motion='reduce')
            assert page.locator('#btnSubmit').evaluate('(e)=>getComputedStyle(e).animationName') == 'none'
            assert page.locator('#dismissMoveGuide').count() == 0
            page.locator('#btnCancel').click()
            page.clock.run_for(31000)
            assert 'still waiting' not in page.locator('#submissionHelp').inner_text()
            assert not f.posts
            f.select()
            f.verify()
            assert page.locator('#submissionHelp').inner_text() == 'Move ready—not submitted. Choose Submit.'
            assert page.locator('#turnstileWidget').bounding_box()['height'] == 0
            assert not f.posts
            page.locator('#btnSubmit').click()
            page.wait_for_function("document.querySelector('#liveGameStatus').textContent.includes('Host')")
            page.clock.run_for(31000)
            assert 'still waiting' not in page.locator('#submissionHelp').inner_text()
            assert len(f.posts) == 1
            f.close()

            f = Fixture(browser, width, pgn='1. e4 e5')
            page = f.page
            page.locator('#btnBack').click()
            before = page.locator('#snapshotPly').inner_text()
            f.square('e4').click(force=True)
            assert 'reviewing an earlier position' in page.locator('#submissionHelp').inner_text()
            assert page.locator('#snapshotPly').inner_text() == before
            assert page.locator('#btnSubmit').is_disabled()
            assert page.locator('#board .selected').count() == 0
            page.locator('#btnLive').click()
            assert 'still waiting' not in page.locator('#submissionHelp').inner_text()
            assert not f.posts
            f.close()
            print(f'PASS {width}px: coaching, 30s reminder, CAPTCHA gating, unified feedback/cancel, reduced motion, review lock', flush=True)
        browser.close()


if __name__ == '__main__':
    main()
