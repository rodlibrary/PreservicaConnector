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

logins = requests.get('http://127.0.0.1:9090/__state', proxies={'http': None}).json()['logins']
print('mock Preservica logins during run:', logins)
print('ALL PASSED')
