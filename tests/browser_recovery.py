#!/usr/bin/env python3
"""Isolated browser recovery checks: no outside requests or native mail transport."""
import json
import os
import sys
sys.dont_write_bytecode = True
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('BLOCKED: optional browser checks require existing Playwright and Chromium.')
    raise SystemExit(77)
from host_authorization import HostAuthorization, START_FEN


def main():
    HostAuthorization.setUpClass()
    fixture = HostAuthorization()
    count = 0
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN','/usr/bin/chromium'),
                headless=True,args=['--no-sandbox','--disable-background-networking'])
            try:
                for width,height in [(1280,900),(390,844)]:
                    for actor in ('host','visitor'):
                        for scenario in ('lost_before_commit','lost_after_commit','malformed_after_commit','refresh_after_loss','refresh_button_after_loss','timeout'):
                            fixture.setUp()
                            (fixture.app/'api/delay.php').write_text('<?php usleep(750000); echo "{}";')
                            host,visitor = ('white','black') if actor=='host' else ('black','white')
                            fixture.execute('UPDATE games SET host_color=?,visitor_color=?',(host,visitor))
                            token=fixture.token() if actor=='host' else None
                            context=browser.new_context(viewport={'width':width,'height':height})
                            if scenario=='timeout':
                                context.add_init_script('''const originalTimeout=window.setTimeout;
                                    let shortenNextSubmissionTimeout=true;
                                    window.setTimeout=(cb,ms,...args)=>{
                                      if(ms===15000 && shortenNextSubmissionTimeout) {
                                        shortenNextSubmissionTimeout=false;
                                        return originalTimeout(cb,250,...args);
                                      }
                                      return originalTimeout(cb,ms,...args);
                                    };''')
                            intercepted=False; submissions=[]
                            endpoint='api/my_move_submit.php' if actor=='host' else 'api/visitor_move.php'
                            def route_request(route):
                                nonlocal intercepted
                                request=route.request
                                if not request.url.startswith(fixture.base):route.abort();return
                                if request.method=='POST' and request.url.endswith(endpoint):
                                    body=request.post_data_json
                                    if body.get('action','submit')=='submit':
                                        submissions.append(body['submission_id'])
                                        if not intercepted:
                                            intercepted=True
                                            if scenario in ('lost_after_commit','malformed_after_commit','refresh_after_loss','refresh_button_after_loss'):
                                                result=route.fetch()
                                                assert result.status==200, 'isolated submit did not commit'
                                            if scenario=='malformed_after_commit':
                                                route.fulfill(status=200,content_type='application/json',body='{broken');return
                                            if scenario=='timeout':
                                                route.continue_(url=fixture.base+'api/delay.php');return
                                            route.abort();return
                                route.continue_()
                            context.route('**/*',route_request)
                            page=context.new_page();errors=[]
                            page.on('pageerror',lambda e:errors.append(type(e).__name__))
                            path='my_move.php?token='+token if actor=='host' else 'index.php'
                            page.goto(fixture.base+path)
                            if actor=='host':page.wait_for_function("document.querySelector('#statusMsg').textContent==='Your turn.'")
                            else:page.wait_for_function("document.body.dataset.gameId==='1'")
                            page.locator('#board .sq').nth(52).click();page.locator('#board .sq').nth(36).click()
                            if actor=='visitor':page.evaluate("window.onTurnstileSuccess('synthetic')")
                            page.locator('#btnSubmit').click()
                            page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('Outcome unconfirmed')")
                            committed=scenario in ('lost_after_commit','malformed_after_commit','refresh_after_loss','refresh_button_after_loss')
                            assert fixture.execute('SELECT revision FROM games')[0]['revision']==int(committed), 'unexpected persistence'
                            if actor=='visitor':
                                saved=page.evaluate("JSON.parse(sessionStorage.getItem('chess-visitor-submission'))")
                                assert set(saved)=={'submission_id','game_id','expected_revision','from','to','promotion'}
                            else:
                                assert page.evaluate('sessionStorage.length')==0, 'host data was stored in session storage'
                            if scenario=='timeout':page.wait_for_timeout(800) # Let the synthetic slow request finish.
                            if scenario in ('refresh_after_loss','refresh_button_after_loss'):
                                if scenario=='refresh_after_loss':page.reload()
                                else:page.locator('#btnRefresh').click()
                                if actor=='host':
                                    page.wait_for_function("document.querySelector('#expiredMessage').textContent.includes('Your move was accepted')")
                                    assert not page.locator('#mainContent').is_visible(), 'receipt restored host authority'
                                else:
                                    page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('Move accepted')")
                                    assert page.evaluate("sessionStorage.getItem('chess-visitor-submission')") is None
                            else:
                                if actor=='visitor':page.evaluate("window.onTurnstileSuccess('synthetic-again')")
                                page.locator('#btnSubmit').click()
                                if actor=='host':page.wait_for_url('**/index.php')
                                else:page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('Waiting on host move')")
                            assert fixture.execute('SELECT revision FROM games')[0]['revision']==1, 'duplicate or missing move'
                            assert len(fixture.execute('SELECT * FROM move_receipts'))==1, 'receipt not unique'
                            assert len(fixture.mailbox())==int(actor=='visitor'), 'duplicate/missing email'
                            assert len(set(submissions))==1, 'retry changed identifier'
                            assert not errors, 'browser script error'
                            count+=1;print(f'PASS {actor} {scenario} at {width}×{height}',flush=True)
                            context.close()
                # A successful move must not be relabeled failed when its follow-up state fetch fails.
                fixture.setUp();fixture.execute("UPDATE games SET host_color='black',visitor_color='white'")
                context=browser.new_context();page=context.new_page();saved=False
                def fail_state_after_move(route):
                    nonlocal saved
                    request=route.request
                    if not request.url.startswith(fixture.base):route.abort();return
                    if request.method=='POST' and request.url.endswith('api/visitor_move.php'):
                        response=route.fetch();saved=True;route.fulfill(response=response);return
                    if saved and request.url.endswith('api/state.php'):
                        route.abort();return
                    route.continue_()
                context.route('**/*',fail_state_after_move)
                page.goto(fixture.base+'index.php');page.wait_for_function("document.body.dataset.gameId==='1'")
                page.locator('#board .sq').nth(52).click();page.locator('#board .sq').nth(36).click()
                page.evaluate("window.onTurnstileSuccess('synthetic')");page.locator('#btnSubmit').click()
                page.wait_for_function("document.querySelector('#statusMsg').textContent.includes('Move accepted. Board refresh failed')")
                assert fixture.execute('SELECT revision FROM games')[0]['revision']==1
                assert page.evaluate("sessionStorage.getItem('chess-visitor-submission')") is None
                count+=1;print('PASS accepted move with failed board refresh',flush=True);context.close()
            finally:browser.close()
        print(f'{count} recovery browser checks passed.')
    finally:HostAuthorization.tearDownClass()


if __name__=='__main__':
    try:main()
    except Exception as error:
        # Browser diagnostics can contain complete request bodies. Print no secrets.
        import traceback
        frames=[f for f in traceback.extract_tb(error.__traceback__) if f.filename==__file__]
        frame=frames[-1]
        print(f'FAIL recovery browser check: {type(error).__name__} at {os.path.basename(frame.filename)}:{frame.lineno}')
        raise SystemExit(1)
