"""End-to-end test: Omeka S + PreservicaConnector against the mock Preservica."""
import re, sys, time, json
import requests
from bs4 import BeautifulSoup

B = 'http://127.0.0.1:8080'
s = requests.Session()
s.trust_env = False
EMAIL, PW = 'admin@example.org', 'Adm1n-password!'


def soup(r):
    return BeautifulSoup(r.text, 'html.parser')


def form_data(page, selector):
    form = page.select_one(selector)
    assert form is not None, 'form %s not found' % selector
    data = {}
    for el in form.select('input, select, textarea'):
        name = el.get('name')
        if not name or el.get('type') in ('checkbox', 'radio', 'submit'):
            continue
        if el.name == 'select':
            opt = el.select_one('option[selected]')
            data[name] = opt.get('value', '') if opt else ''
        else:
            data[name] = el.get('value', '') if el.name == 'input' else el.text
    return form, data


def messages(page):
    return [m.get_text(' ', strip=True) for m in page.select('ul.messages li')]


def check(label, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + label + (' -- ' + str(detail) if detail else ''))
    if not ok:
        sys.exit(1)


def wait_job(job_id):
    for _ in range(90):
        page = soup(s.get(f'{B}/admin/job/{job_id}'))
        text = page.get_text(' ', strip=True)
        if re.search(r'Status\s+(Completed|Error|Stopped)', text):
            return re.search(r'Status\s+(\w+)', text).group(1)
        time.sleep(1)
    return 'timeout'


# --- install Omeka S (first run only) and log in
r = s.get(B + '/', allow_redirects=True)
if '/install' in r.url:
    form, data = form_data(soup(r), 'form')
    data.update({
        'user[email]': EMAIL, 'user[email-confirm]': EMAIL, 'user[name]': 'Admin',
        'user[password-confirm][password]': PW, 'user[password-confirm][password-confirm]': PW,
        'settings[installation_title]': 'Test', 'settings[time_zone]': 'UTC', 'settings[locale]': '',
    })
    r = s.post(B + '/install', data=data)
    check('Omeka S installed', '/login' in r.url, r.url)
r = s.get(B + '/login')
form, data = form_data(soup(r), 'form')
data.update({'email': EMAIL, 'password': PW})
r = s.post(B + '/login', data=data)
check('logged in', '/admin' in r.url, r.url)

# --- install the module (first run only)
page = soup(s.get(B + '/admin/module'))
inst = page.select_one('form[action*="module/install?id=PreservicaConnector"]')
if inst:
    r = s.post(B + inst['action'], data={'csrf': inst.select_one('input[name=csrf]')['value']})
    check('module installed', r.ok, r.url)
page = soup(s.get(B + '/admin/module'))
check('module active', page.find('a', href=re.compile(r'module/configure\?id=PreservicaConnector')) is not None)

# --- unconfigured state
if '--fresh' in sys.argv:
    page = soup(s.get(B + '/admin/preservica-connector'))
    check('unconfigured notice shown', 'has not been set up' in page.get_text())

# --- configure with a wrong password, then the right one
def configure(password):
    r = s.get(B + '/admin/module/configure?id=PreservicaConnector')
    form, data = form_data(soup(r), '#content form')
    data.update({
        'preservicaconnector_host': 'http://127.0.0.1:9090/',
        'preservicaconnector_username': 'svc-omeka@uni.example',
        'preservicaconnector_password': password,
        'preservicaconnector_fallback_preservation': '1',
        'preservicaconnector_store_reference': '1',
    })
    return s.post(B + '/admin/module/configure?id=PreservicaConnector', data=data)

r = configure('wrong')
check('bad credentials reported', any('connection test failed' in m and 'Invalid username' in m for m in messages(soup(r))), messages(soup(r)))
r = configure('s3cret')
check('good credentials accepted', any('Connected to Preservica' in m for m in messages(soup(r))), messages(soup(r)))
r = s.get(B + '/admin/module/configure?id=PreservicaConnector')
check('password not echoed to browser', 's3cret' not in r.text)
check('blank password keeps stored one', any('Connected' in m for m in messages(soup(configure('')))))

# --- search
r = s.get(B + '/admin/preservica-connector', params={'q': 'campus'})
page = soup(r)
refs = [i['value'] for i in page.select('input[name="refs[]"]')]
check('search "campus" finds 2 assets', len(refs) == 2, refs)
check('search shows titles', 'Campus aerial photograph, 1954' in page.get_text())
r = s.get(B + '/admin/preservica-connector', params={'q': 'zzz-nothing'})
check('empty search handled', 'No assets found' in r.text)
r = s.get(B + '/admin/preservica-connector', params={'q': '%'})
page = soup(r)
refs = [i['value'] for i in page.select('input[name="refs[]"]')]
check('search "%" lists all 3', len(refs) == 3, refs)

# --- thumbnail relay
thumb = page.select_one('.preservica-asset img')['src']
r = s.get(B + thumb)
check('thumbnail relayed', r.status_code == 200 and r.headers['Content-Type'].startswith('image/png') and r.content[:4] == b'\x89PNG', (r.status_code, r.headers.get('Content-Type')))
r = s.get(B + '/admin/preservica-connector/thumbnail/00000000-0000-4000-8000-000000000000')
check('unknown thumbnail is 404', r.status_code == 404, r.status_code)

# --- import requires CSRF and a selection
r = s.post(B + '/admin/preservica-connector/import', data={'refs[]': refs})
check('import without CSRF token rejected', any('CSRF' in m for m in messages(soup(r))), messages(soup(r)))
form, data = form_data(page, '#preservica-import-form')
r = s.post(B + '/admin/preservica-connector/import', data=dict(data))
check('import with no selection rejected', any('Select at least one' in m for m in messages(soup(r))), messages(soup(r)))

# --- import all three
page = soup(s.get(B + '/admin/preservica-connector', params={'q': '%'}))
form, data = form_data(page, '#preservica-import-form')
data['refs[]'] = refs + ['not-a-uuid']
data['comment'] = 'e2e run'
r = s.post(B + '/admin/preservica-connector/import', data=data)
m = re.search(r'/admin/job/(\d+)', r.text)
check('import job dispatched', m is not None, messages(soup(r)))
job_id = m.group(1)
status = wait_job(job_id)
check('import job completed', status == 'Completed', status)
print('--- job log ---')
print(s.get(f'{B}/admin/job/{job_id}/log').text.strip())
print('---------------')

api = lambda path, **p: s.get(B + '/api/' + path, params=p).json()
items = api('items', preservica_import_job=job_id, sort_by='id')
by_title = {i['o:title']: i for i in items}
check('2 items created, 1 failed', sorted(by_title) == ['Campus aerial photograph, 1954', 'Commencement program'], sorted(by_title))

aerial = by_title['Campus aerial photograph, 1954']
check('DC creator mapped', aerial['dcterms:creator'][0]['@value'] == 'University Photo Service')
subjects = aerial['dcterms:subject']
check('DC and MODS subjects merged without duplicates', [v.get('@value') or v.get('o:label') for v in subjects] == ['Aerial photographs', 'Campus planning'], subjects)
check('MODS authority link kept as URI with label', subjects[0]['type'] == 'uri' and subjects[0]['@id'] == 'http://id.loc.gov/authorities/subjects/sh85001255')
check('same title in DC and MODS stored once', len(aerial['dcterms:title']) == 1)
check('MODS place mapped to spatial coverage', aerial['dcterms:spatial'][0]['@value'] == 'Cedar Falls (Iowa)')
check('MODS extent mapped', aerial['dcterms:extent'][0]['@value'] == '1 photograph')
check('URL value stored as URI', aerial['dcterms:rights'][0]['type'] == 'uri' and aerial['dcterms:rights'][0]['@id'].startswith('http://rightsstatements.org'))
check('Preservica ref stored as identifier', aerial['dcterms:identifier'][0]['@value'] == 'a1111111-1111-4111-8111-111111111111')
media = [s.get(m['@id']).json() for m in aerial['o:media']]
check('access copies used, not the preservation master', [m['o:source'] for m in media] == ['aerial-front.jpg', 'aerial-back.jpg'], [m['o:source'] for m in media])
check('media stored as JPEG with thumbnails', all(m['o:media_type'] == 'image/jpeg' and m['o:thumbnail_urls'].get('large') and m['o:ingester'] == 'preservica' for m in media))
r = s.get(media[0]['o:original_url'])
check('original file served from Omeka', r.status_code == 200 and r.content[:3] == b'\xff\xd8\xff' and len(r.content) == media[0]['o:size'], (r.status_code, len(r.content)))

program = by_title['Commencement program']
check('MODS-only record: title mapped once', [v['@value'] for v in program['dcterms:title']] == ['Commencement program'])
check('MODS-only record: creator, date and type mapped', program['dcterms:creator'][0]['@value'] == 'State Teachers College' and program['dcterms:date'][0]['@value'] == '1931-06-02' and program['dcterms:type'][0]['@value'] == 'text')
check('description falls back to Preservica description', program['dcterms:description'][0]['@value'].startswith('Printed program'))
media = [s.get(m['@id']).json() for m in program['o:media']]
check('preservation copy used when no access copy', [m['o:source'] for m in media] == ['program.png'] and media[0]['o:media_type'] == 'image/png')

# --- past imports and "already imported"
page = soup(s.get(B + '/admin/preservica-connector/past-imports'))
text = page.get_text(' ', strip=True)
check('past imports shows counts', '2 added, 3 files, 1 failed' in text and 'e2e run' in text, text[-400:])
page = soup(s.get(B + '/admin/preservica-connector', params={'q': '%'}))
disabled = [i['value'] for i in page.select('input[name="refs[]"][disabled]')]
check('imported assets flagged and not selectable', len(disabled) == 2, disabled)
r = s.get(B + '/admin/item', params={'preservica_import_job': job_id})
check('item browse filter works', 'Commencement program' in r.text and 'Campus aerial' in r.text)

# --- re-import skips duplicates
form, data = form_data(page, '#preservica-import-form')
data['refs[]'] = refs
r = s.post(B + '/admin/preservica-connector/import', data=data)
job2 = re.search(r'/admin/job/(\d+)', r.text).group(1)
check('second import completed', wait_job(job2) == 'Completed')
text = soup(s.get(B + '/admin/preservica-connector/past-imports')).get_text(' ', strip=True)
check('duplicates skipped', '0 added, 0 files, 2 already imported, 1 failed' in text, text[-500:])
check('no duplicate items', len(api('items')) == len(items))

# --- undo
page = soup(s.get(B + '/admin/preservica-connector/past-imports'))
form, data = form_data(page, '#preservica-undo-form')
data['undo[]'] = [job_id]
r = s.post(B + '/admin/preservica-connector/past-imports', data=data)
undo_id = None
for _ in range(60):
    text = soup(s.get(B + '/admin/preservica-connector/past-imports')).get_text(' ', strip=True)
    m = re.search(r'Undone in job #(\d+)', text)
    if m:
        undo_id = m.group(1)
        break
    time.sleep(1)
check('undo job dispatched', undo_id is not None)
check('undo job completed', wait_job(undo_id) == 'Completed')
check('items deleted by undo', len(api('items', preservica_import_job=job_id)) == 0 and len(api('items')) == 0, len(api('items')))
r = s.get(aerial['o:media'][0]['@id'])
check('media deleted by undo', r.status_code == 404, r.status_code)

# --- folders
F = lambda n: 'f%07d-0000-4000-8000-000000000000' % n
BROWSE = B + '/admin/preservica-connector/browse'


def import_row(job):
    """The past-imports table row for an import job, as text."""
    page = soup(s.get(B + '/admin/preservica-connector/past-imports'))
    for row in page.select('table.preservica-imports tbody tr'):
        if row.find('a', string='Job #%s' % job):
            return row.get_text(' ', strip=True)
    return ''


def import_folder(ref, recursive='1', item_set='folder', comment=''):
    page = soup(s.get(BROWSE, params={'folder': ref}))
    form, data = form_data(page, '#preservica-folder-form')
    data.update({'recursive': recursive, 'item_set': item_set, 'comment': comment})
    r = s.post(B + '/admin/preservica-connector/import-folder', data=data)
    m = re.search(r'/admin/job/(\d+)', r.text)
    check('folder import dispatched', m is not None, messages(soup(r)))
    check('folder import job completed', wait_job(m.group(1)) == 'Completed', m.group(1))
    return m.group(1)


def undo(job):
    page = soup(s.get(B + '/admin/preservica-connector/past-imports'))
    form, data = form_data(page, '#preservica-undo-form')
    data['undo[]'] = [job]
    s.post(B + '/admin/preservica-connector/past-imports', data=data)
    for _ in range(60):
        m = re.search(r'Undone in job #(\d+)', import_row(job))
        if m:
            check('undo of job %s completed' % job, wait_job(m.group(1)) == 'Completed')
            return m.group(1)
        time.sleep(1)
    check('undo of job %s dispatched' % job, False)


def item_sets():
    return {i['o:title']: i for i in api('item_sets', per_page=100)}


def item_set_size(item_set):
    return len(api('items', item_set_id=item_set['o:id'], per_page=500))


page = soup(s.get(BROWSE))
names = [a.get_text(strip=True) for a in page.select('.preservica-folder-row a.preservica-title')]
check('top level lists the 3 top folders', names == ['University Archives', 'Yearbooks', 'Empty folder'], names)
check('no folder import form at the top level', page.select_one('#preservica-folder-form') is None)
page = soup(s.get(BROWSE, params={'folder': F(2)}))
crumbs = [li.get_text(strip=True) for li in page.select('.preservica-breadcrumbs li')]
check('breadcrumbs show the folder path', crumbs == ['Top level', 'University Archives', 'Photographs'], crumbs)
check('subfolders and assets listed', [a.get_text(strip=True) for a in page.select('.preservica-folder-row a.preservica-title')] == ['Oral histories'] and len(page.select('input[name="refs[]"]')) == 1)
check('folder form defaults: subfolders on, folder item set', page.select_one('#preservica-folder-recursive').has_attr('checked') and page.select_one('#preservica-folder-item-set option[selected]')['value'] == 'folder')
check('default item set option names the folder', 'New item set: Photographs' in page.select_one('#preservica-folder-item-set').get_text())
check('browse page 1 of 120 assets shows 100', len(soup(s.get(BROWSE, params={'folder': F(4)})).select('input[name="refs[]"]')) == 100)
check('browse page 2 shows the other 20', len(soup(s.get(BROWSE, params={'folder': F(4), 'page': 2})).select('input[name="refs[]"]')) == 20)
r = s.get(BROWSE, params={'folder': '../../etc'})
check('invalid folder reference falls back to the top level', r.status_code == 200 and 'University Archives' in r.text)

empty_job = import_folder(F(5))
check('empty folder imports nothing and makes no item set', '0 added' in import_row(empty_job) and not item_sets(), item_sets().keys())

# The aerial photograph is imported on its own first, outside any item set.
page = soup(s.get(B + '/admin/preservica-connector', params={'q': 'aerial'}))
form, data = form_data(page, '#preservica-import-form')
data['refs[]'] = ['a1111111-1111-4111-8111-111111111111']
single_job = re.search(r'/admin/job/(\d+)', s.post(B + '/admin/preservica-connector/import', data=data).text).group(1)
check('single asset imported', wait_job(single_job) == 'Completed' and '1 added' in import_row(single_job))

archives_job = import_folder(F(1), comment='whole collection')
row = import_row(archives_job)
check('collection import walks subfolders', '1 added, 1 files, 1 already imported, 1 failed' in row, row)
check('past imports names the folder', 'Folder: University Archives, with subfolders' in row and 'whole collection' in row, row)
sets = item_sets()
check('one item set named after the top folder', list(sets) == ['University Archives'], list(sets))
archives = sets['University Archives']
check('item set has the folder description and reference', archives['dcterms:description'][0]['@value'] == 'Records of the university.' and archives['dcterms:identifier'][0]['@value'] == F(1))
titles = sorted(i['o:title'] for i in api('items', item_set_id=archives['o:id']))
check('new and already-imported assets from all subfolders are in the item set', titles == ['Campus aerial photograph, 1954', 'Commencement program'], titles)
log = s.get(f'{B}/admin/job/{archives_job}/log').text
check('job log explains the already-imported asset', 'already imported as item' in log and 'added it to item set' in log, log)

again_job = import_folder(F(1), recursive='0')
row = import_row(again_job)
check('re-import without subfolders: 1 already imported', '0 added, 0 files, 1 already imported' in row and 'with subfolders' not in row, row)
check('re-import reuses the folder item set', list(item_sets()) == ['University Archives'] and item_set_size(archives) == 2)
page = soup(s.get(BROWSE, params={'folder': F(1)}))
check('browse page notes the earlier import', 'has been imported before' in page.get_text() and "This folder's item set: University Archives" in page.select_one('#preservica-folder-item-set').get_text())

yearbooks_job = import_folder(F(4))
row = import_row(yearbooks_job)
check('120-asset folder imported across pages', '120 added, 0 files' in row, row)
yearbooks = item_sets()['Yearbooks']
check('all 120 in the Yearbooks item set', item_set_size(yearbooks) == 120)

undo(archives_job)
check('undo removes only the items that import created', [i['o:title'] for i in api('items', item_set_id=archives['o:id'])] == ['Campus aerial photograph, 1954'])
check('undo keeps the item set while it still has items', 'University Archives' in item_sets())
undo(yearbooks_job)
check('undo removes the folder items', len(api('items', per_page=500)) == 1)
check('undo removes the now-empty item set it created', 'Yearbooks' not in item_sets(), list(item_sets()))

logins = requests.get('http://127.0.0.1:9090/__state', proxies={'http': None}).json()['logins']
print('mock Preservica logins during run:', logins)
print('ALL PASSED')
