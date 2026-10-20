"""API com MySQL descartável, sem chamadas às integrações."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]
dsn = os.environ.get('M3L_TEST_DSN', '')
if not re.search(r'dbname=m3l_test_[a-z0-9_]+(?:;|$)', dsn):
    raise SystemExit('Use somente um banco descartável m3l_test_*.')
env = os.environ | {
    'DB_DSN': dsn,
    'DB_USER': os.environ.get('M3L_TEST_USER', 'root'),
    'DB_PASSWORD': os.environ.get('M3L_TEST_PASSWORD', ''),
}
with tempfile.TemporaryDirectory() as directory:
    target = Path(directory)
    (target / 'public/assets').mkdir(parents=True)
    (target / 'private').mkdir()
    shutil.copy(root / 'public/api.php', target / 'public/api.php')
    shutil.copy(root / 'public/assets/countries.json', target / 'public/assets/countries.json')
    shutil.copy(root / 'private/storage.php', target / 'private/storage.php')
    (target / 'private/config.php').write_text("<?php return ['whatsapp_url'=>'https://sndflw.com/i/DlpYsVbaM63GsH6UkXZo', 'api_key'=>''];")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}/api.php?action='
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(action, data=None, csrf=None, client=opener):
        headers = {'Content-Type': 'application/json'}
        if csrf is not None:
            headers['X-CSRF-Token'] = csrf
        request = urllib.request.Request(base + action, data=json.dumps(data).encode() if data is not None else None, headers=headers)
        try:
            response = client.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, json.load(response)

    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(target / 'public')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                status, initial = call('init')
                break
            except urllib.error.URLError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Servidor não iniciou.')
        assert status == 200 and not initial['registered'] and not initial['whatsapp']
        csrf = initial['csrf']
        payload = {'name': 'Teste HTTP', 'email': 'http@example.com', 'ddi': '55', 'phone': '11999999999', 'website': ''}
        assert call('signup', payload)[0] == 403
        assert call('signup', payload | {'phone': '1'}, csrf)[0] == 422
        assert call('survey', {'answers': ['x'] * 10}, csrf)[0] == 401
        started = time.monotonic()
        status, result = call('signup', payload, csrf)
        elapsed = time.monotonic() - started
        assert status == 202 and result['redirect'] == 'obrigado.html'
        assert call('signup', payload, csrf)[0] == 202
        status, registered = call('init')
        assert registered['registered'] and registered['whatsapp'].startswith('https://sndflw.com/')
        answers = ['http@example.com', 'Feminino', '32'] + ['Resposta'] * 7
        assert call('survey', {'answers': answers}, csrf)[0] == 202
        assert call('survey', {'answers': answers}, csrf)[0] == 202
        assert call('init')[1]['survey_done']
        print(f'OK: API real aceita cadastro em {elapsed:.3f}s no ambiente de teste, sem chave ActiveCampaign; CSRF, validação, repetição, sessão, pesquisa e SendFlow verificados.')
    finally:
        server.terminate()
        server.wait(timeout=10)
