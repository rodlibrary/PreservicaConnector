"""Real-browser check of the select -> import -> undo flow (forms, JS, layout)."""
import re, sys
from playwright.sync_api import sync_playwright
B='http://127.0.0.1:8080'
def check(label, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ')+label+(' -- '+str(detail) if detail else ''))
    if not ok: sys.exit(1)
with sync_playwright() as p:
    b=p.chromium.launch(channel='chrome', args=['--no-sandbox','--no-proxy-server'])
    pg=b.new_page(viewport={'width':1280,'height':900})
    pg.route('**/code.jquery.com/**', lambda r: r.abort())
    pg.goto(B+'/login'); pg.fill('input[name=email]','admin@example.org'); pg.fill('input[name=password]','Adm1n-password!'); pg.click('input[type=submit], button[type=submit]')
    pg.goto(B+'/admin/preservica-connector?q=%25'); pg.wait_for_load_state('load')
    check('import button disabled with nothing selected', pg.is_disabled('#preservica-import-button'))
    pg.check('#preservica-select-all')
    check('select-all selects 3', pg.inner_text('#preservica-selected-count')=='3 selected', pg.inner_text('#preservica-selected-count'))
    pg.uncheck('#preservica-ref-2')
    check('count updates', pg.inner_text('#preservica-selected-count')=='2 selected')
    pg.fill('#preservica-comment','browser run')
    pg.screenshot(path='search.png', full_page=True)
    pg.click('#preservica-import-button'); pg.wait_for_load_state('load')
    check('redirected to past imports', pg.url.endswith('/past-imports'), pg.url)
    check('success message', 'Importing 2 Preservica asset(s)' in pg.inner_text('ul.messages'), pg.inner_text('ul.messages'))
    for _ in range(40):
        pg.wait_for_timeout(1000); pg.reload()
        if '2 added' in pg.inner_text('#content'): break
    check('browser import added 2 items', '2 added, 3 files' in pg.inner_text('#content'))
    pg.screenshot(path='past.png', full_page=True)
    pg.goto(B+'/admin/preservica-connector?q=campus'); pg.wait_for_load_state('load')
    pg.screenshot(path='search2.png', full_page=True)
    pg.goto(B+'/admin/preservica-connector/past-imports')
    pg.locator('input[name="undo[]"]:not([disabled])').first.check()
    pg.click('#page-actions button[type=submit]'); pg.wait_for_load_state('load')
    check('undo message', 'Undoing import job' in pg.inner_text('ul.messages'), pg.inner_text('ul.messages'))
    for _ in range(40):
        pg.wait_for_timeout(1000)
        pg.goto(B+'/admin/preservica-connector?q=%25')
        if pg.locator('input[name="refs[]"][disabled]').count()==0: break
    check('undo removed the items', pg.locator('input[name="refs[]"][disabled]').count()==0)
    pg.goto(B+'/admin/module/configure?id=PreservicaConnector'); pg.screenshot(path='config.png', full_page=True)
    b.close()
print('BROWSER FLOW PASSED')
