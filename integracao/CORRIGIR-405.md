# Recuperação de POST de navegação

Os logs fornecidos mostraram POST /v2/ recusados com 405, referer da própria página e user agents do Instagram no iPhone. Não foi identificado o gatilho original do POST nem demonstrado que havia dados de cadastro em cada requisição.

A correção trata apenas as páginas de inscrição V1/V2/V3: GET/HEAD continuam estáticos, e POST recusado é encaminhado internamente, mantendo método e corpo, a um handler PHP. O handler renderiza a mesma página, inclui rascunho dos campos conhecidos (com JSON escapado) e pede ao visitante para conferir e clicar novamente em participar. Não redireciona descartando o corpo, não salva lead, não envia Active/Sheets, não marca Lead, não dispensa CSRF da API. Corpo máximo 16 KiB. Conteúdo com rascunho recebe Cache-Control no-store, private. Não há dados pessoais na URL nem no log acrescentado pela correção.

O visitante precisa confirmar o envio pelo fluxo normal. Se o navegador não transmitir os campos, não é possível recuperá-los; o formulário pede preenchimento novamente. Requisições antigas que já falharam não podem ser reconstruídas a partir do access.log.

## Ativar como root na VPS

```bash
cd /var/www/m3l
git pull --ff-only origin main && python3 deploy/fix-navigation-405.py
```

O instalador salva o arquivo Nginx anterior, detecta o fastcgi_pass já usado pela API (inclusive PHP 8.5), testa nginx -t e só faz reload após passar. Não altera TLS, credenciais, banco, workers ou configuração de /api.php. Interrompe sem modificar se encontra configuração inesperada. Em falha de validação/reload, restaura o arquivo anterior. Não é necessário reiniciar workers. Repetir é seguro.

## Teste sem cadastro externo

```bash
curl -sS -o /dev/null -w 'GET: %{http_code}\n' https://m3l.thaysassis.com.br/v2/
curl -sS -X POST --data '' -o /dev/null -w 'POST: %{http_code}\n' https://m3l.thaysassis.com.br/v2/
```

Ambos devem responder 200. Abrir novamente o story no Instagram/iPhone e testar a inscrição real para confirmar o comportamento no aparelho. O handler não envia cadastro nos testes acima. Validar GET/HEAD e POST em / e /v3/ também se usados. Buscar novas ocorrências no access.log pelo horário após a ativação (as antigas permanecem).

O diagnóstico da fila não detecta esses visitantes rejeitados antes do cadastro: fila zerada não comprova que todos os visitantes conseguiram se inscrever.

## Reverter

Restaurar o caminho de backup impresso pelo instalador no arquivo /etc/nginx/sites-available/m3l, executar nginx -t e reload. Reverter o commit se necessário. Nenhuma tabela ou cadastro precisa ser apagado/restaurado.

## Validação local

Testes com Nginx 1.24 e PHP-FPM 8.3 reais passaram: GET/HEAD, POST com corpo e vazio nas três versões, preservação de campos, Cache-Control no-store, dados maliciosos sem injeção HTML e bloqueio de acesso direto ao handler. Testes Python da alteração Nginx (incluindo preservação do socket PHP 8.5 e rejeição de rotas personalizadas), Node do rascunho e UTMs também passaram. O teste não reproduz o aplicativo Instagram real; validar nele após ativação.
