"""Real-browser check of browsing folders and importing one."""
import sys
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
F1 = 'f0000001-0000-4000-8000-000000000000'
def check(label, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + label + (' -- ' + str(detail) if detail else ''))
    if not ok:
        sys.exit(1)
with sync_playwright() as p:
    b = p.chromium.launch(channel='chrome', args=['--no-sandbox', '--no-proxy-server'])
    pg = b.new_page(viewport={'width': 1280, 'height': 900})
    pg.route('**/code.jquery.com/**', lambda r: r.abort())
    pg.goto(B + '/login'); pg.fill('input[name=email]', 'admin@example.org'); pg.fill('input[name=password]', 'Adm1n-password!'); pg.click('input[type=submit], button[type=submit]')
    pg.goto(B + '/admin/preservica-connector')
    pg.click('nav a:has-text("Browse folders")'); pg.wait_for_load_state('load')
    check('menu link opens the folder browser', '/browse' in pg.url, pg.url)
    pg.screenshot(path='browse-top.png', full_page=True)
    pg.click('a.preservica-title:has-text("University Archives")'); pg.wait_for_load_state('load')
    check('clicking a folder opens it', 'folder=' + F1 in pg.url)
    pg.screenshot(path='browse-folder.png', full_page=True)
    pg.click('a.preservica-title:has-text("Photographs")'); pg.wait_for_load_state('load')
    pg.click('.preservica-breadcrumbs a:has-text("University Archives")'); pg.wait_for_load_state('load')
    check('breadcrumb goes back up', 'folder=' + F1 in pg.url)
    pg.click('#preservica-folder-import-button'); pg.wait_for_load_state('load')
    check('folder import starts', 'Importing a Preservica folder' in pg.inner_text('ul.messages'), pg.inner_text('ul.messages'))
    for _ in range(40):
        pg.wait_for_timeout(1000); pg.reload()
        if '2 added' in pg.inner_text('#content'):
            break
    check('folder import finished', '2 added, 2 files' in pg.inner_text('#content') or '2 added' in pg.inner_text('#content'), pg.inner_text('#content')[:400])
    pg.screenshot(path='past-folder.png', full_page=True)
    b.close()
print('BROWSER FOLDER FLOW PASSED')
