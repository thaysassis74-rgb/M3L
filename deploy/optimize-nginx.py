#!/usr/bin/env python3
"""Apply performance settings to the existing vhost, preserving TLS and PHP."""
import pathlib, re, shutil, subprocess, sys, time

path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '/etc/nginx/sites-available/m3l').resolve()
original = path.read_text()
start, end = '# BEGIN M3L PERFORMANCE', '# END M3L PERFORMANCE'
text = re.sub(r'\n?\s*# BEGIN M3L PERFORMANCE.*?# END M3L PERFORMANCE\n?', '\n', original, flags=re.S)
assets = r'location\s+/assets/\s*\{[^{}]*\}'
if len(re.findall(assets, text)) != 1:
    sys.exit('Esperava um único bloco location /assets/. Nenhuma alteração aplicada.')
text = re.sub(assets, 'location /assets/ {\n        expires 30d;\n        add_header Cache-Control "public";\n        try_files $uri =404;\n    }', text)
# A redirect here loops when index internally resolves / to /index.html.
text = re.sub(r'\s*location\s*=\s*/index\.html\s*\{\s*return 302 /\$is_args\$args;\s*\}', '', text)
if re.search(r'location\s+(?:~|=).*?(?:css|js)', text):
    sys.exit('Já existe regra CSS/JS personalizada. Nenhuma alteração aplicada.')
block = '''
    # BEGIN M3L PERFORMANCE
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml;
    location ~* \\.(?:css|js)$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }
    # END M3L PERFORMANCE
'''
anchor = re.search(r'client_max_body_size\s+[^;]+;', text)
if not anchor:
    sys.exit('Não encontrei o bloco esperado. Nenhuma alteração aplicada.')
text = text[:anchor.end()] + '\n' + block + text[anchor.end():]
backup = path.with_name(path.name + '.before-performance-' + str(time.time_ns()))
shutil.copy2(path, backup)
path.write_text(text)
if subprocess.run(['nginx', '-t']).returncode:
    shutil.copy2(backup, path)
    sys.exit('Configuração restaurada: nginx -t não passou.')
if subprocess.run(['systemctl', 'reload', 'nginx']).returncode:
    shutil.copy2(backup, path)
    subprocess.run(['nginx', '-t'])
    sys.exit('Falha no reload. Arquivo anterior restaurado; confira o serviço.')
print('Cache e compressão aplicados. Backup:', backup)
