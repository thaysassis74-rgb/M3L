# Ativar UTMs

1. No Apps Script vinculado à planilha, substituir o código pelo arquivo `integracao/Pesquisa.gs`.
2. Salvar e ir a Implantar > Gerenciar implantações > Editar > Nova versão > Implantar. Manter a mesma implantação e URL e a propriedade PESQUISA_TOKEN existente.
3. Na VPS:

```bash
cd /var/www/m3l
git pull --ff-only origin main
sudo -u www-data php bin/migrate-utms.php
sudo systemctl restart m3l-worker@activecampaign m3l-worker@sheets
```

Executar a migração imediatamente após o pull. Ela cria uma tabela adicional, sem apagar cadastros, e pode ser repetida. Os workers precisam ser reiniciados para carregar o novo código.

Testar abrindo `/v2/?utm_source=teste&utm_medium=manual&utm_campaign=validacao&utm_content=botao&utm_term=aula`, cadastrando e concluindo a pesquisa. Na V1, usar a mesma query na raiz. As UTMs serão gravadas nas colunas P a T; o ID do envio continua em O. O script acrescenta os cabeçalhos automaticamente se essas colunas estiverem vazias.

A atribuição é a última visita com UTMs na sessão da aba. Visitas sem UTMs não apagam a campanha, e novos links de campanha substituem o conjunto anterior. O cadastro mantém a atribuição inicial em caso de reenvio da mesma tentativa. Dados antigos sem captura continuam sem UTMs; a correção não inventa origens.

Validação local: `node tests/utms.cjs` e `php tests/integrations.php`. Os testes usam mocks, sem enviar cadastros reais. Validar banco e fluxo real após a implantação; os testes locais não substituem essa etapa.
