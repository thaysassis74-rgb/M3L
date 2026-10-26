# Otimização da fila e proteção dos dados existentes

Mantém MySQL, tabelas, credenciais, URLs e fluxo de cadastro da VPS. Não exige CREATE, ALTER, limpeza do banco nem migração. Não há paralelismo adicional: um lock exclusivo por integração preserva o ritmo e evita workers duplicados.

## Alterações

- Reutiliza o handle cURL e conexões TCP/TLS entre chamadas; não desabilita validação HTTPS.
- Intervalo mínimo de 250 ms depois de cada chamada (até 4/s deste worker). ActiveCampaign permite 5/s por conta; outras integrações da mesma conta compartilham esse limite. HTTP 429 continua respeitando Retry-After globalmente.
- IDs procurados por nome ficam em memória no worker. IDs já configurados continuam sendo usados sem pesquisa. Reiniciar o worker após alterar lista/tag.
- Falhas temporárias permanecem pendentes mesmo após 12 tentativas. Cada tarefa tem backoff de até 6h mais jitter; a pausa global é limitada a 60s, exceto Retry-After maior. Uma tarefa problemática não bloqueia todos os cadastros por horas.
- Erros permanentes, inclusive restrição de inscrição, continuam failed para revisão; não reinscreve quem saiu nem repete essas falhas automaticamente.
- Reconciliação cria apenas tarefas ausentes e retoma falhas temporárias legadas reconhecidas; não reenvia tarefas concluídas. Repetição após timeout pode repetir chamadas externas, mas usa sync por e-mail, consulta lista/tag e ID fixo da pesquisa.
- Diagnóstico sem nomes/e-mails/tokens e backup diário comprimido com arquivo temporário, lock e verificação gzip antes de publicar o arquivo final.

## Ativar na VPS como root

```bash
cd /var/www/m3l
git pull --ff-only origin main
bash bin/backup-mysql.sh
```

Se o backup falhar, pare aqui e confira a mensagem. Ele usa o administrador local do MySQL via socket, sem copiar a senha do aplicativo. Não conceda privilégios novos ao usuário m3l. Não muda configuração do MySQL.

Com o backup concluído:

```bash
sudo -u www-data php tests/integrations.php
sudo -u www-data php bin/reconcile.php
sudo systemctl restart m3l-worker@activecampaign m3l-worker@sheets
install -m 644 deploy/m3l-backup.service deploy/m3l-backup.timer deploy/m3l-health.service deploy/m3l-health.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now m3l-backup.timer m3l-health.timer
php bin/health.php
sudo -u www-data php bin/queue.php failed
```

Não executar tests/database.php no banco real. Essa suíte só aceita banco descartável m3l_test_* e apaga suas tabelas de teste.

Atalho equivalente, após o git pull: `bash bin/activate-reliability.sh`. Interrompe se o backup ou os testes falharem.

## Validação desta entrega

Testes com PHP 8.3 e MariaDB 10.11 descartável: transações, rollback, exclusão de worker duplicado, Retry-After, retomada, mais de 12 tentativas, restrições permanentes, avanço de outros cadastros e reconciliação idempotente passaram. Testes de UTMs com Node passaram. Scripts de shell passaram em bash -n. A instalação dos timers e o dump/restauração do banco de produção precisam ser verificados na VPS; não houve acesso SSH a ela nesta entrega.

## Monitoramento

```bash
systemctl list-timers m3l-backup.timer m3l-health.timer
journalctl -u m3l-health.service -n 20 --no-pager
journalctl -u m3l-backup.service -n 20 --no-pager
sudo -u www-data php bin/queue.php
```

Diagnóstico retorna status 1 e detalhes no journal quando há failed, espera >10min, processamento >10min, cadastro sem tarefa, disco <15% ou backup acessível ausente/antigo (>25h). O timer de saúde roda como root para conferir os backups restritos. Isso é alerta local do systemd, não mensagem por WhatsApp/e-mail. Nenhuma mensagem externa é enviada.

Os backups ficam em /var/backups/m3l, acesso somente root, sem exclusão automática. Monitorar espaço e definir retenção conforme volume. Backup local não protege contra perda total da VPS; configurar cópia externa ou backup do provedor e testar restauração em banco separado. gzip -t verifica integridade do arquivo comprimido, não substitui teste de restauração. Esta entrega não ativa backup externo nem altera a conta DigitalOcean.

`done` significa que as chamadas esperadas da integração foram aceitas, não quantidade de novos e-mails únicos nem confirmação posterior da automação do Active. Confrontar lista/tag no painel quando necessário.

## Reversão

Reverter o commit de otimização e reiniciar os workers restaura o código anterior, sem restaurar/apagar banco. Tarefas, cadastros e backups permanecem. Não executar SQL de exclusão. Desativar timers com systemctl disable --now m3l-health.timer m3l-backup.timer se necessário.
