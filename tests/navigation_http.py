"""Teste local com Nginx/PHP-FPM reais; não chama API nem integrações externas.
Defina NGINX_BIN, PHP_FPM_BIN e FASTCGI_PARAMS se fora do PATH/sistema.
"""
import importlib.util,json,os,pathlib,re,socket,subprocess,tempfile,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('patch',root/'deploy/fix-navigation-405.py')
patch=importlib.util.module_from_spec(spec);spec.loader.exec_module(patch)
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
http_port,fpm_port=port(),port()
with tempfile.TemporaryDirectory(prefix='m3l-navigation-') as folder:
    d=pathlib.Path(folder);d.chmod(0o755)
    fpm=d/'fpm.conf';fpm.write_text(f'''[global]
error_log={d}/fpm.log
daemonize=no
[www]
user=root
group=root
listen=127.0.0.1:{fpm_port}
pm=static
pm.max_children=1
''')
    base=f'''server {{ listen 127.0.0.1:{http_port}; root /var/www/m3l/public; index index.html;
location / {{ try_files $uri $uri/ =404; }}
location = /api.php {{ include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/api.php; fastcgi_pass 127.0.0.1:{fpm_port}; }}
location ~ \\.php$ {{ return 404; }}
}}'''
    server=patch.patched(base).replace('/var/www/m3l/public',str(root/'public'))
    server=server.replace('include fastcgi_params;', 'include '+os.getenv('FASTCGI_PARAMS','/etc/nginx/fastcgi_params')+';')
    conf=d/'nginx.conf';conf.write_text(f'''user root root; daemon off; master_process off; pid {d}/nginx.pid;
error_log {d}/nginx.log;
events {{ worker_connections 32; }}
http {{ access_log off; client_body_temp_path {d}/body; fastcgi_temp_path {d}/fastcgi; proxy_temp_path {d}/proxy; uwsgi_temp_path {d}/uwsgi; scgi_temp_path {d}/scgi; {server} }}''')
    processes=[]
    stderr=(d/'startup.log').open('w')
    try:
        processes.append(subprocess.Popen([os.getenv('PHP_FPM_BIN','php-fpm8.3'),'-n','-F','-R','-y',str(fpm)],stdout=subprocess.DEVNULL,stderr=stderr))
        processes.append(subprocess.Popen([os.getenv('NGINX_BIN','nginx'),'-p',folder,'-c',str(conf)],stdout=subprocess.DEVNULL,stderr=stderr))
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',fpm_port),timeout=.2):pass
                break
            except OSError:time.sleep(.1)
        else:raise RuntimeError('PHP-FPM indisponível')
        url=f'http://127.0.0.1:{http_port}'
        for _ in range(100):
            try:
                with urllib.request.urlopen(url+'/v2/',timeout=1) as response:assert response.status==200
                break
            except (OSError,AssertionError):time.sleep(.1)
        else:raise RuntimeError('Servidor de teste indisponível')
        for route in ['/','/v2/','/v2/index.html','/v3/','/v3/index.html']:
            for method in ['GET','HEAD']:
                with urllib.request.urlopen(urllib.request.Request(url+route,method=method)) as response:assert response.status==200
            payload=b'name=%3C%2Fscript%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E&email=local%40example.com&phone=11999999999&ddi=55'
            with urllib.request.urlopen(urllib.request.Request(url+route+'?utm_source=instagram',data=payload)) as response:
                body=response.read().decode();assert response.status==200
                assert 'no-store' in response.headers['Cache-Control']
                match=re.search(r'id="m3l-navigation-draft">(.*?)</script>',body)
                assert match,body[:200]
                fields=json.loads(match[1])['fields'];assert fields['email']=='local@example.com' and fields['phone']=='11999999999'
                assert '</script><script>alert(1)</script>' not in body
            with urllib.request.urlopen(urllib.request.Request(url+route,data=b'')) as response:assert response.status==200
        try:urllib.request.urlopen(url+'/recover-navigation.php')
        except urllib.error.HTTPError as error:assert error.code==404
        else:raise AssertionError('Handler não deve ser exposto diretamente')
        print('OK: GET/HEAD, POST vazio/com campos, preservação do corpo, no-store, XSS e handler restrito em Nginx/PHP-FPM reais.')
    except Exception:
        for name in ['startup.log','nginx.log','fpm.log']:
            p=d/name
            if p.exists():print(p.read_text()[-2000:])
        raise
    finally:
        for p in processes:p.terminate()
        for p in processes:
            try:p.wait(timeout=5)
            except subprocess.TimeoutExpired:p.kill()
