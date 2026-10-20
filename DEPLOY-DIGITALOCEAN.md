# Instalação da fila — DigitalOcean

O navegador só aguarda a gravação no MySQL. Cadastro e tarefa são gravados na mesma transação; se o banco falhar, a API retorna erro e mantém o formulário na tela. Depois de salvar, abre `obrigado.html` diretamente, sem o modal intermediário. A pesquisa também é salva localmente antes de confirmar o check-in.

Há um worker por integração: ActiveCampaign e Google Sheets. O cadastro é aceito mesmo se uma integração estiver fora do ar. Isso significa **recebido no banco**, não confirmação de entrega externa. Consulte a fila após instalar e durante a campanha.

## 1. Preparar a VPS

Exemplo para **Droplet Ubuntu 24.04 LTS**, executado por SSH com usuário que pode usar sudo. Os comandos instalam os pacotes, mas não criam nem contratam recursos na DigitalOcean. Em uma VPS existente, confira os sites atuais antes de alterar Nginx.

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-curl php8.3-mysql php8.3-mbstring git certbot python3-certbot-nginx
sudo systemctl enable --now nginx mysql php8.3-fpm
```

## 2. Colocar o projeto na pasta

Para uma instalação nova (a pasta de destino deve estar vazia):

```bash
sudo git clone --branch feat/fila-cadastros https://github.com/thaysassis74-rgb/M3L.git /var/www/m3l
cd /var/www/m3l
```

Depois que o PR for integrado, use a branch `main` em instalações futuras. Se já houver instalação, faça backup dos arquivos e **principalmente de `private/config.php`** antes de atualizar. A nova branch deixa de versionar esse arquivo; mantenha sua cópia privada no servidor. Não faça `git pull` sobre um site em campanha sem preparar e testar a versão primeiro.

## 3. Criar banco e usuário

```bash
sudo mysql
```

No prompt MySQL, substitua `SUBSTITUA_POR_UMA_SENHA_FORTE` por uma senha exclusiva:

```sql
CREATE DATABASE m3l CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'm3l'@'127.0.0.1' IDENTIFIED BY 'SUBSTITUA_POR_UMA_SENHA_FORTE';
GRANT SELECT, INSERT, UPDATE ON m3l.* TO 'm3l'@'127.0.0.1';
EXIT;
```

Importe as tabelas como administrador (o usuário do site não precisa criar ou apagar tabelas):

```bash
sudo mysql m3l < /var/www/m3l/database/schema.sql
```

Mantenha o MySQL acessível apenas localmente. Não abra a porta 3306 na internet.

## 4. Preencher os arquivos privados

Para uma instalação nova:

```bash
cd /var/www/m3l
sudo cp -n private/config.example.php private/config.php
sudo cp -n private/database.example.php private/database.php
sudo nano private/database.php
```

Preencha `dsn`, `user` e `password` com o banco do passo 3. O DSN de exemplo usa `127.0.0.1`, como o usuário que criamos.

```bash
sudo nano private/config.php
```

Preencha `api_key`, `sheets_webhook_url` e `sheets_webhook_token`. O token deve ser o mesmo da propriedade `PESQUISA_TOKEN` do Apps Script. O exemplo já inclui o convite SendFlow. `list_id=6` e `tag_id=1` correspondem à conta/lista/tag do evento verificadas no projeto; se mudar de conta, confira os IDs ou remova esses dois campos para pesquisar pelos nomes.

Os workers e o PHP-FPM usam os mesmos arquivos. Variáveis de ambiente, se definidas, têm prioridade; um `WHATSAPP_GROUP_URL` antigo substituirá o link do arquivo.

```bash
sudo chown -R root:www-data /var/www/m3l
sudo chmod 750 /var/www/m3l/private
sudo chmod 640 /var/www/m3l/private/config.php /var/www/m3l/private/database.php
sudo -u www-data php -r 'require "/var/www/m3l/private/storage.php"; database(); echo "Banco OK\n";'
```

As chaves que já foram publicadas no histórico precisam ser substituídas nos serviços. Os arquivos reais de configuração agora são ignorados pelo Git; removê-los da versão atual não apaga o histórico anterior.

## 5. Apontar o domínio e configurar o site

No DNS do domínio, crie um registro A apontando para o IP público da VPS. Se usar `www`, configure também esse nome. Aguarde o DNS apontar para o servidor.

```bash
sudo cp /var/www/m3l/deploy/nginx.conf.example /etc/nginx/sites-available/m3l
sudo nano /etc/nginx/sites-available/m3l
```

Troque `evento.exemplo.com` pelo domínio real. O exemplo usa o socket do PHP 8.3. A raiz deve ser **`/var/www/m3l/public`**; nunca `/var/www/m3l`. O trecho `limit_req_zone` deve ficar no contexto `http` do Nginx, como acontece no `sites-enabled` padrão do Ubuntu. Se seu painel usa outra estrutura, coloque-o no contexto correspondente.

```bash
sudo ln -s /etc/nginx/sites-available/m3l /etc/nginx/sites-enabled/m3l
sudo nginx -t
sudo systemctl reload nginx
```

Ative HTTPS usando seu domínio real:

```bash
sudo certbot --nginx -d evento.exemplo.com
```

Abra HTTP/HTTPS no firewall da VPS e no firewall da DigitalOcean, preservando a regra SSH. Não use `php -S` para atender a campanha; ele serve apenas para desenvolvimento.

## 6. Ativar as duas filas

```bash
sudo cp /var/www/m3l/deploy/m3l-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now m3l-worker@activecampaign m3l-worker@sheets
sudo systemctl status m3l-worker@activecampaign m3l-worker@sheets --no-pager
```

Depois de alterar credenciais ou arquivos PHP dos workers, reinicie os serviços:

```bash
sudo systemctl restart m3l-worker@activecampaign m3l-worker@sheets
```

Não crie um cron que inicia novos workers a cada minuto. O systemd mantém os dois processos ativos, e um lock do MySQL impede dois workers da mesma integração ao mesmo tempo.

## 7. Fazer o teste completo antes dos anúncios

1. Cadastre um endereço de teste controlado por você. A página de obrigada deve abrir após a gravação local.
2. Confira que o botão abre o convite SendFlow e responda às dez perguntas.
3. Confira o contato na lista e tag corretas da ActiveCampaign e a linha na aba `Pesquisa`.
4. Inspecione a fila: as duas tarefas devem chegar a `done`.
5. Em ambiente de homologação, pare os workers, faça outro cadastro/pesquisa, confira que ficam `pending` e reinicie os serviços. Os dados devem ser entregues depois.
6. Faça teste de carga em homologação antes de definir capacidade. Esta versão prioriza a durabilidade e usa um worker por integração; a fila pode crescer quando a entrada for maior que a velocidade de envio. Não há promessa de um número de acessos simultâneos sem medir a VPS.

```bash
cd /var/www/m3l
sudo -u www-data php bin/queue.php
sudo journalctl -u m3l-worker@activecampaign -u m3l-worker@sheets -n 100 --no-pager
```

Sessões da versão antiga não têm o ID local do novo banco. Quem já estava com a página aberta antes da atualização deve fazer o cadastro novamente. Não existe migração automática de contatos históricos da ActiveCampaign para o MySQL.

## 8. Acompanhar falhas

As tarefas usam até 12 tentativas, com espera progressiva e pausa da integração em falhas temporárias. `Retry-After` é respeitado. HTTP 4xx permanentes e restrições de inscrição viram `failed` e exigem revisão; não são descartados. As respostas da pesquisa permanecem no banco mesmo que o Google Sheets esteja indisponível.

```bash
sudo -u www-data php /var/www/m3l/bin/queue.php failed
```

Após corrigir a causa, reagende **somente o ID da tarefa** desejada (troque `123`):

```bash
sudo -u www-data php /var/www/m3l/bin/queue.php retry 123
```

O reagendamento respeita uma pausa ainda ativa da integração. Logs não incluem nome, e-mail, respostas ou chaves. Configure acompanhamento da idade/quantidade de pendentes, tarefas `failed`, disco e backups do MySQL. Ativar backup só dos arquivos da VPS não substitui um backup consistente do banco; verifique também uma restauração em homologação.

## Testes do código

`php tests/integrations.php` usa somente respostas simuladas. `tests/database.php` exige um **banco descartável** cujo nome começa com `m3l_test_`; ele apaga e recria as tabelas de teste. Nunca use o banco da campanha. O workflow `Testes da fila` executa ambos no GitHub com MySQL 8 e sem credenciais reais.

Referências: [transações PDO](https://www.php.net/manual/pt_BR/pdo.transactions.php) e [locks do MySQL](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html).
