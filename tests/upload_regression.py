#!/usr/bin/env python3
"""Isolated HTTP regression: PHP and Python 3.6+, no production config/data read."""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

REPO = Path(__file__).resolve().parent.parent
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=')


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS: ' + message, flush=True)


def run():
    with tempfile.TemporaryDirectory(prefix='video-upload-test-') as temporary:
        root = Path(temporary)
        (root / 'server').mkdir()
        for name in ['common.php', 'auth.php', 'upload_sessions.php', 'upload.php', 'generate.php']:
            shutil.copyfile(str(REPO / 'server' / name), str(root / 'server' / name))
        shutil.copyfile(str(REPO / 'index.php'), str(root / 'index.php'))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = 'http://127.0.0.1:{}/'.format(port)
        (root / 'server' / 'config.php').write_text("<?php const BASE_URL = '" + base + "';")
        setup = "require 'server/common.php'; createUser('owner@example.test','test-only-pass','owner'); createUser('client@example.test','test-only-pass','client','', ['tribute_name'=>'Locked tribute']);"
        subprocess.check_call(['php', '-r', setup], cwd=str(root))
        (root / 'probe.php').write_text("<?php echo json_encode(['max_files'=>(int)ini_get('max_file_uploads')]);")
        with (root / 'server.log').open('wb') as log:
            process = subprocess.Popen(['php', '-d', 'max_file_uploads=1', '-d', 'post_max_size=8M', '-S', '127.0.0.1:{}'.format(port), '-t', str(root)], stdout=log, stderr=log)
            try:
                for attempt in range(100):
                    try:
                        with urllib.request.urlopen(base + 'probe.php', timeout=1) as response:
                            check(json.load(response)['max_files'] == 1, 'PHP really allows only ONE file per request')
                        break
                    except (OSError, urllib.error.URLError):
                        time.sleep(0.05)
                else:
                    raise RuntimeError('Test server failed to start')

                def client():
                    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

                def post(browser, path, fields, files=(), header=True):
                    boundary = uuid.uuid4().hex
                    chunks = []
                    for key, value in fields.items():
                        chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + str(value) + '\r\n').encode())
                    for key, name, content in files:
                        chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"; filename="' + name + '"\r\nContent-Type: image/png\r\n\r\n').encode() + content + b'\r\n')
                    chunks.append(('--' + boundary + '--\r\n').encode())
                    headers = {'Content-Type': 'multipart/form-data; boundary=' + boundary}
                    if header:
                        headers['X-Video-Upload'] = '1'
                    request = urllib.request.Request(base + path, data=b''.join(chunks), headers=headers)
                    try:
                        response = browser.open(request, timeout=30)
                    except urllib.error.HTTPError as error:
                        response = error
                    with response:
                        body = response.read().decode()
                        try:
                            body = json.loads(body)
                        except ValueError:
                            pass
                        return response.code, body

                owner, user, anonymous = client(), client(), client()
                for browser, email in [(owner, 'owner@example.test'), (user, 'client@example.test')]:
                    status, body = post(browser, 'server/auth.php', {'action': 'login', 'email': email, 'password': 'test-only-pass'})
                    check(status == 200 and 'Générateur de vidéo MP4' in body, 'test account logs in through real auth endpoint')
                check(post(anonymous, 'server/upload.php', {'action': 'start'})[0] == 401, 'anonymous upload denied')
                check(post(user, 'server/upload.php', {'action': 'start'}, header=False)[0] == 403, 'cross-origin form request denied')
                status, started = post(user, 'server/upload.php', {'action': 'start'})
                check(status == 200, 'client starts staging session')
                token = started['upload_token']

                def upload(id, content=PNG, browser=user):
                    return post(browser, 'server/upload.php', {'upload_token': token, 'file_id': id}, [('files[' + id + ']', id + '.png', content)])

                check(upload('stolen', browser=owner)[0] == 400, 'another account cannot use the token')
                check(upload('../escape')[0] == 400, 'path traversal file ID denied')
                check(upload('invalid', b'not an image')[0] == 400, 'invalid image content rejected')
                ids = ['photo-{:03d}'.format(n) for n in range(100)]
                for id in ids:
                    status, result = upload(id)
                    assert status == 200 and result.get('ok'), (id, status, result)
                check(True, 'all 100 files uploaded with max_file_uploads=1')
                check(upload(ids[0])[0] == 200, 'same-file retry is idempotent')
                check(upload('photo-101')[0] == 400, '101st file rejected')
                check(not list((root / 'jobs').glob('*.json')), 'no partial montage queued during upload')
                fields = {'upload_token': token, 'order_json': json.dumps(list(reversed(ids))), 'image_duration': 5, 'title_duration': 4, 'intro_title': 'Opening', 'outro_title': 'Closing', 'homage_from': 'Customized dedication', 'tribute_name': 'Unauthorized change'}
                incomplete = dict(fields, order_json=json.dumps(ids[:-1]))
                check(post(user, 'server/generate.php', incomplete)[0] == 400, 'incomplete selection cannot be finalized')
                check(post(user, 'server/generate.php', dict(fields, order_json=json.dumps([ids[0]] * 100)))[0] == 400, 'duplicate ordering IDs rejected')
                check(post(user, 'server/generate.php', dict(fields, image_duration=6))[0] == 400, '600-second limit includes title cards')
                check(post(user, 'server/generate.php', fields, [('logo', 'logo.png', PNG)])[0] == 403, 'client cannot upload an owner-only logo')
                status, result = post(user, 'server/generate.php', fields)
                check(status == 200 and result.get('ok'), '100-photo montage finalized successfully')
                job_id = result['job_id']
                job = json.loads((root / 'jobs' / (job_id + '.json')).read_text())
                check(len(job['media']) == 100 and [m['original_name'] for m in job['media']] == [id + '.png' for id in reversed(ids)], 'all 100 photos retained in requested order')
                check(job['homage_from'] == 'Customized dedication' and job['tribute_name'] == 'Locked tribute', 'client dedication editable; protected tribute preserved')
                check(len(list((root / 'uploads' / job_id).glob('*.png'))) == 100, 'all 100 queued source files exist')
                check(not list((root / 'uploads' / ('batch_' + token)).glob('*.png')), 'duplicate staged media released after finalization')
                retry_status, retry = post(user, 'server/generate.php', fields)
                check(retry_status == 200 and retry['job_id'] == job_id and len(list((root / 'jobs').glob('*.json'))) == 1, 'finalization retry does not duplicate the job')
                check(upload(ids[0])[0] == 409, 'finalized upload cannot be modified')
                _, started = post(owner, 'server/upload.php', {'action': 'start'})
                token = started['upload_token']
                check(upload('single', browser=owner)[0] == 200, 'owner upload supported')
                status, result = post(owner, 'server/generate.php', {'upload_token': token, 'order_json': '["single"]'}, [('logo', 'logo.png', PNG)])
                owner_job = json.loads((root / 'jobs' / (result['job_id'] + '.json')).read_text())
                check(status == 200 and owner_job['logo_file'], 'owner logo sent separately despite PHP one-file limit')
                _, expiring = post(user, 'server/upload.php', {'action': 'start'})
                token = expiring['upload_token']
                manifest = root / 'uploads' / ('batch_' + token) / 'manifest.json'
                state = json.loads(manifest.read_text())
                state['created_at'] = 1
                manifest.write_text(json.dumps(state))
                check(upload('expired')[0] == 400, 'expired token denied')
                os.utime(str(manifest), (1, 1))
                post(user, 'server/upload.php', {'action': 'start'})
                check(not manifest.parent.exists() and (root / 'uploads' / job_id).exists(), 'cleanup removes expired staging, not project media')
                print('All upload regression checks passed.', flush=True)
            finally:
                process.terminate()
                process.wait(timeout=10)


if __name__ == '__main__':
    run()
