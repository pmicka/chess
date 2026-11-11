#!/usr/bin/env python3
"""Isolated public UI accessibility/reflow/performance checks, plus shared CSS smoke.

Uses the PHP-stripped shell and mocked API/Turnstile from browser_interactions.
No production requests or real endpoint validation. Optional AXE_CORE_PATH points
at an existing axe-core bundle; the test never downloads or installs anything.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import urlparse

sys.dont_write_bytecode = True
from browser_interactions import Fixture, ROOT, position, sync_playwright, wait_text

TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']


def audit(page, name, axe_path):
    if not axe_path:
        return {'state': name, 'axe': 'not run: AXE_CORE_PATH not supplied'}
    if not page.evaluate('!!window.axe'):
        page.add_script_tag(path=str(axe_path))
    result = page.evaluate('''async (tags) => {
      const result = await axe.run(document, {runOnly: {type: 'tag', values: tags}});
      return {version: axe.version,
        violations: result.violations.map(v=>({id:v.id,impact:v.impact,
          nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),
        incomplete: result.incomplete.map(v=>({id:v.id,count:v.nodes.length,
          examples:v.nodes.slice(0,2).map(n=>({target:n.target,summary:n.failureSummary}))}))};
    }''', TAGS)
    assert not result['violations'], json.dumps({'state': name, **result}, indent=2)
    return {'state': name, **result}


def geometry(page):
    result = page.evaluate('''() => ({viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,
      overflow:[...document.querySelectorAll('.stage-card *')].filter(e=>{
        const r=e.getBoundingClientRect();return r.width>0&&(r.left<0||r.right>innerWidth+1);
      }).map(e=>e.id||e.className),
      smallButtons:[...document.querySelectorAll('.public-game button')].filter(e=>{
        const r=e.getBoundingClientRect();return r.width>0&&(r.width<24||r.height<24);
      }).map(e=>({id:e.id,w:e.getBoundingClientRect().width,h:e.getBoundingClientRect().height}))})''')
    assert not result['overflow'], result
    assert not result['smallButtons'], result
    assert result['documentWidth'] <= result['viewport'] + 1, result
    return result


def accessibility_tree(page):
    client = page.context.new_cdp_session(page)
    try:
        nodes = client.send('Accessibility.getFullAXTree')['nodes']
        return [(node.get('role', {}).get('value'), node.get('name', {}).get('value', ''))
                for node in nodes if not node.get('ignored')]
    finally:
        client.detach()


def poll(page):
    with page.expect_response(lambda response: response.url.endswith('/api/state.php')):
        page.evaluate('window.runPoll()')
    page.evaluate('()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')


def contrast(foreground, background):
    def luminance(rgb):
        values = [n / 255 for n in rgb]
        values = [n / 12.92 if n <= .04045 else ((n + .055) / 1.055) ** 2.4 for n in values]
        return sum(n * weight for n, weight in zip(values, (.2126, .7152, .0722)))
    a, b = luminance(foreground), luminance(background)
    return (max(a, b) + .05) / (min(a, b) + .05)


def marker_contrast(page, selector, prop, pseudo=None):
    color = page.locator(selector).first.evaluate('(e,args)=>getComputedStyle(e,args[1])[args[0]]', [prop, pseudo])
    channels = list(map(float, re.findall(r'[\d.]+', color)))
    foreground = channels[:3]
    alpha = channels[3] if len(channels) > 3 else 1
    ratios = []
    for shade in ('.light', '.dark'):
        color = page.locator('#board .sq' + shade).first.evaluate('(e)=>getComputedStyle(e).backgroundColor')
        background = tuple(map(int, re.findall(r'\d+', color)[:3]))
        composited = tuple(round(f * alpha + b * (1 - alpha)) for f, b in zip(foreground, background))
        ratio = contrast(composited, background)
        assert ratio >= 3, (selector, foreground, background, ratio)
        ratios.append(round(ratio, 2))
    return ratios


def quality(browser, width, axe_path):
    f = Fixture(browser, width, pgn='1. e4 e5 2. Nf3 Nc6')
    page = f.page
    results = []
    assert page.locator('main').count() == 1
    page.keyboard.press('Tab')
    assert page.get_by_role('link', name='Skip to chessboard').evaluate('(e)=>e===document.activeElement')
    assert page.get_by_role('link', name='Skip to chessboard').bounding_box()['y'] >= 0
    page.keyboard.press('Enter')
    assert page.locator('#board').evaluate('(e)=>e===document.activeElement')
    page.keyboard.press('Tab')
    assert page.locator('#board .sq[tabindex="0"]').evaluate('(e)=>e===document.activeElement')
    tree = accessibility_tree(page)
    assert sum(role == 'button' and bool(re.match(r'^[a-h][1-8], ', name)) for role, name in tree) == 64
    assert ('button', 'Toggle game details') in tree
    assert page.locator('#detailsToggle, #hudDetailsPanel, .board-help, #notationStatus').count() == 0
    assert page.locator('#historyStatus, #historyNotice').count() == 0
    assert page.locator('.status-stack').is_hidden()
    geometry(page)
    results.append(audit(page, 'initial', axe_path))

    f.square('f3').click()
    assert f.square('e5').evaluate('(e)=>e.classList.contains("hint-capture")')
    assert f.square('e5').evaluate('(e)=>getComputedStyle(e,"::before").borderTopWidth') == '0px'
    marker_ratios = {'capture_destination_dot': marker_contrast(page, '#board .hint-capture', 'backgroundColor', '::before')}
    f.square('f1').click()
    assert page.locator('#board .hint-capture').count() == 0
    assert page.locator('#board .pick').evaluate('(e)=>getComputedStyle(e).outlineStyle') == 'none'
    marker_ratios['legal_destination_dot'] = marker_contrast(page, '#board .hint', 'backgroundColor', '::before')
    f.square('b5').click()
    assert page.locator('#board .last').first.evaluate('(e)=>getComputedStyle(e).boxShadow') == 'none'
    assert page.locator('#board .last').first.evaluate('(e)=>getComputedStyle(e).backgroundImage') != 'none'
    geometry(page)
    results.append(audit(page, 'candidate awaiting CAPTCHA', axe_path))
    f.verify()
    results.append(audit(page, 'verified candidate', axe_path))
    page.evaluate('window.onTurnstileExpired()')
    results.append(audit(page, 'expired CAPTCHA', axe_path))
    page.locator('#btnCancel').click()
    page.locator('#btnBack').click()
    results.append(audit(page, 'history', axe_path))
    assert ('button', 'Toggle game details') in accessibility_tree(page)
    page.locator('#toggleNotation').click()
    geometry(page)
    results.append(audit(page, 'history with live notation', axe_path))
    assert page.locator('.notation-readout').count() == 0
    assert ('button', 'Copy FEN') in accessibility_tree(page)
    page.locator('#toggleNotation').click()
    assert ('button', 'Copy FEN') not in accessibility_tree(page)

    page.emulate_media(reduced_motion='reduce')
    page.evaluate('scrollTo(0,500)')
    page.wait_for_function("document.querySelector('#backToTopButton').classList.contains('visible')")
    page.evaluate("()=>{window.originalScrollTo=window.scrollTo; window.scrollTo=function(options){window.lastScrollBehavior=options?.behavior}}")
    page.locator('#backToTopButton').click()
    assert page.evaluate('window.lastScrollBehavior') == 'auto'
    page.evaluate('()=>{window.scrollTo=window.originalScrollTo;scrollTo(0,0)}')
    assert page.locator('#statusSpinner').evaluate('(e)=>getComputedStyle(e).animationName') == 'none'
    assert page.locator('#btnSubmit').evaluate('(e)=>parseFloat(getComputedStyle(e).transitionDuration)') <= .001
    page.emulate_media(forced_colors='active')
    assert page.locator('#board .light').first.evaluate('(e)=>getComputedStyle(e).backgroundColor') != page.locator('#board .dark').first.evaluate('(e)=>getComputedStyle(e).backgroundColor')
    geometry(page)
    page.emulate_media(forced_colors='none')
    # Stress inherited text at twice the default size. This is not native browser text zoom.
    page.evaluate("document.documentElement.style.fontSize='200%'")
    page.locator('#toggleNotation').click()
    geometry(page)
    page.evaluate("document.documentElement.style.fontSize=''")
    page.locator('#toggleNotation').click()

    fen = '4k3/P7/8/8/8/8/8/4K3 w - - 0 1'
    f.state.update(fen=fen, pgn=f'[SetUp "1"]\n[FEN "{fen}"]\n\n*', revision=1,
                   turn_color='white', visitor_color='white', you_color='black')
    page.locator('#btnLive').click()
    page.wait_for_function("document.querySelectorAll('#board .piece-img').length===3")
    f.select('a7', 'a8')
    geometry(page)
    results.append(audit(page, 'promotion', axe_path))
    f.state.update(position('1. f3 e5 2. g4 Qh4#'), revision=2, status='finished')
    page.locator('#btnRefresh').click()
    page.locator('#gameOverBanner').wait_for(state='visible')
    geometry(page)
    results.append(audit(page, 'game over', axe_path))
    assert not f.posts
    f.close()
    print(f'PASS {width}px: 8 accessibility states, AX tree, skip link, targets, reflow, reduced motion, forced colors, text stress', flush=True)
    return {'width': width, 'states': results, 'marker_contrast_light_dark': marker_ratios}


def shared_css(browser):
    old = subprocess.check_output(['git', 'show', 'HEAD:assets/css/style.css'], cwd=ROOT, text=True)
    new = (ROOT / 'assets/css/style.css').read_text()
    sample = '''<div class="wrap"><div class="card stage-card"><div class="board-container">
      <div class="board-shell"><div id="board"></div></div></div><div class="control-bay">
      <button id="sampleSubmit">Submit move</button><button class="secondary">Refresh</button>
      <input value="sample"><textarea>PGN</textarea></div></div></div>'''
    context = browser.new_context()
    context.route('**/*', lambda route: route.abort())
    for width in (320, 390, 1280):
        page = context.new_page()
        page.set_viewport_size({'width': width, 'height': 900})
        values = []
        for css in (old, new):
            page.set_content('<style>' + css + '</style>' + sample)
            values.append(page.evaluate('''()=>[...document.querySelectorAll('.wrap,.stage-card,#board,button,input,textarea')].map(e=>{
              let r=e.getBoundingClientRect(),s=getComputedStyle(e);return {x:r.x,y:r.y,w:r.width,h:r.height,
                color:s.color,background:s.backgroundColor,padding:s.padding,columns:s.gridTemplateColumns};})'''))
        assert values[0] == values[1], width
        page.close()
    context.close()
    print('PASS shared host/review class geometry and styling unchanged at 320/390/1280px', flush=True)


def performance(browser):
    f = Fixture(browser, 390)
    page = f.page
    page.wait_for_load_state('networkidle')
    # Initialize Playwright's selector instrumentation before measuring app
    # listener growth; its first locator call installs its own event handlers.
    initial_count = page.locator('*').count()
    client = f.context.new_cdp_session(page)
    client.send('Performance.enable')
    client.send('HeapProfiler.collectGarbage')
    before = client.send('Memory.getDOMCounters')
    metrics_before = {m['name']: m['value'] for m in client.send('Performance.getMetrics')['metrics']}
    for _ in range(20):
        poll(page)
    client.send('HeapProfiler.collectGarbage')
    after = client.send('Memory.getDOMCounters')
    metrics_after = {m['name']: m['value'] for m in client.send('Performance.getMetrics')['metrics']}
    assert page.locator('*').count() == initial_count
    assert after['nodes'] <= before['nodes'] + 10, (before, after)
    assert after['jsEventListeners'] <= before['jsEventListeners'] + 2, (before, after)
    assert page.evaluate('window.renderCount||0') == 0
    assert not f.posts
    resources = page.evaluate("performance.getEntriesByType('resource').map(e=>new URL(e.name).pathname)")
    assert resources.count('/chess/assets/chess.min.js') == 1
    assert '/chess/assets/bg.webp' in resources and '/chess/assets/bg.jpg' not in resources
    result = {'polls': 20, 'dom_before_gc': before, 'dom_after_gc': after,
              'live_element_count': initial_count,
              'script_ms_during_poll_loop': round((metrics_after['ScriptDuration']-metrics_before['ScriptDuration'])*1000, 2),
              'layout_count_during_poll_loop': metrics_after['LayoutCount']-metrics_before['LayoutCount'],
              'captcha_widgets_before_selection': 0,
              'note': 'Local headless fixture; not a production load-time, paint, network, or mobile-device benchmark.'}
    client.detach()
    f.close()
    print('PASS 20 polls: no DOM/listener growth after GC, one engine load, WebP chosen, no early CAPTCHA', flush=True)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--axe-path', type=Path, default=os.environ.get('AXE_CORE_PATH'))
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    if args.axe_path and not Path(args.axe_path).is_file():
        parser.error('axe-core bundle not found')
    if not args.axe_path:
        print('SKIP axe scans: provide --axe-path or AXE_CORE_PATH; structural checks still run.', flush=True)
    report = {'local_frontend_only': True, 'axe_requested': bool(args.axe_path), 'viewports': []}
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN', '/usr/bin/chromium'),
                                    args=['--no-sandbox', '--disable-background-networking'])
        report['chromium_version'] = browser.version
        for width in (320, 390, 768, 1280):
            report['viewports'].append(quality(browser, width, args.axe_path))
        shared_css(browser)
        report['performance'] = performance(browser)
        f = Fixture(browser, 844)
        f.page.set_viewport_size({'width': 844, 'height': 390})
        geometry(f.page)
        f.close()
        print('PASS short landscape viewport 844x390', flush=True)
        browser.close()
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print('PASS frontend UI quality checks; manual/PHP/staging checks remain separate.', flush=True)


if __name__ == '__main__':
    main()
