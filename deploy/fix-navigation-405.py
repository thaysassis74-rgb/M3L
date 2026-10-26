#!/usr/bin/env python3
"""Recupera POSTs de navegação sem confirmar cadastros ou mudar /api.php."""
import pathlib, re, shutil, subprocess, sys, time
START = '# BEGIN M3L NAVIGATION RECOVERY'
END = '# END M3L NAVIGATION RECOVERY'
PAGES = {'/':'/index.html', '/index.html':'/index.html', '/v2':'/v2/index.html', '/v2/':'/v2/index.html', '/v2/index.html':'/v2/index.html', '/v3':'/v3/index.html', '/v3/':'/v3/index.html', '/v3/index.html':'/v3/index.html'}

def patched(original):
    text = re.sub(r'\n[ \t]*# BEGIN M3L NAVIGATION RECOVERY.*?# END M3L NAVIGATION RECOVERY\n?', '\n', original, flags=re.S)
    api = re.findall(r'location\s*=\s*/api\.php\s*\{([^{}]*)\}', text)
    if len(api)!=1: raise ValueError('Esperava um único location = /api.php. Nenhuma alteração aplicada.')
    passes=re.findall(r'fastcgi_pass\s+([^;]+);',api[0])
    if len(passes)!=1: raise ValueError('Socket PHP não identificado. Nenhuma alteração aplicada.')
    if len(re.findall(r'root\s+/var/www/m3l/public\s*;',text))!=1: raise ValueError('Root inesperado. Nenhuma alteração aplicada.')
    for route in PAGES:
        if re.search(r'location\s*=\s*'+re.escape(route)+r'\s*\{',text): raise ValueError('Rota já personalizada: '+route+'. Nenhuma alteração aplicada.')
    anchors=list(re.finditer(r'location\s+/\s*\{',text))
    if len(anchors)!=1: raise ValueError('Bloco raiz ambíguo. Nenhuma alteração aplicada.')
    block='\n    '+START+'\n'
    for route,file in PAGES.items():
        block+=f'    location = {route} {{\n        error_page 405 = @m3l_navigation_recovery;\n        try_files {file} =404;\n    }}\n'
    block+='''    location @m3l_navigation_recovery {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/m3l/public/recover-navigation.php;
        fastcgi_pass '''+passes[0]+''';
    }
    '''+END+'\n\n    '
    at=anchors[0].start()
    return text[:at]+block+text[at:]

def main():
    path=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/etc/nginx/sites-available/m3l').resolve()
    if subprocess.run(['php','-l','/var/www/m3l/public/recover-navigation.php']).returncode:
        sys.exit('Handler PHP inválido. Nenhuma alteração aplicada.')
    original=path.read_text()
    try: result=patched(original)
    except ValueError as error: sys.exit(str(error))
    backup=path.with_name(path.name+'.before-navigation-'+str(time.time_ns()))
    shutil.copy2(path,backup)
    path.write_text(result)
    if subprocess.run(['nginx','-t']).returncode:
        shutil.copy2(backup,path)
        sys.exit('Configuração restaurada: nginx -t falhou. Backup: '+str(backup))
    if subprocess.run(['systemctl','reload','nginx']).returncode:
        shutil.copy2(backup,path)
        subprocess.run(['nginx','-t'])
        sys.exit('Arquivo anterior restaurado; reload falhou. Backup: '+str(backup))
    print('Recuperação de navegação ativada. Backup:',backup)
if __name__=='__main__': main()
