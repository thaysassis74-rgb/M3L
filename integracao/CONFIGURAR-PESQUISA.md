# Receber a pesquisa no Google Sheets

O destino configurado é a planilha original Pesquisa, aba Pesquisa. A integração não altera o arquivo Excel anexado.

1. Abra a planilha original no Google Sheets e vá a **Extensões → Apps Script**.
2. Substitua o código inicial pelo conteúdo de `Pesquisa.gs` e salve.
3. Em **Configurações do projeto → Propriedades do script**, adicione `PESQUISA_TOKEN` com uma senha aleatória longa, de preferência 32 caracteres ou mais. Guarde esse valor.
4. Clique em **Implantar → Nova implantação → Aplicativo da Web**. Configure executar como **você** e acesso **Qualquer pessoa**. Autorize o acesso à planilha quando o Google solicitar. Se sua organização bloquear acesso público, essa rota precisa ser substituída por autenticação via conta de serviço.
5. Copie a URL da implantação que termina em `/exec`.
6. Em `private/config.php`, preencha `sheets_webhook_url` com essa URL e `sheets_webhook_token` com exatamente o mesmo valor de `PESQUISA_TOKEN`. As credenciais ficam somente no servidor PHP.
7. Faça um cadastro próprio e conclua a pesquisa. Confirme uma nova linha na aba Pesquisa. A confirmação na página só aparece quando o webhook retorna sucesso.

A primeira resposta cria os cabeçalhos na aba vazia. Se já houver cabeçalhos diferentes, o script interrompe o envio sem sobrescrevê-los. Respostas repetidas na mesma sessão atualizam a mesma linha, usando ID único e trava para evitar duplicação simultânea. Texto é gravado de forma a evitar execução de fórmulas.

O cadastro e a lista/tag continuam no ActiveCampaign. As respostas da pesquisa passam a ir para o Google Sheets, em vez das notas do ActiveCampaign. O campo `survey_fields` da configuração anterior deixa de ser usado.

A publicação e autorização no Google ainda precisam ser realizadas. A conexão real não está ativa antes de configurar a URL e o token. Não envie tokens em URLs ou no JavaScript público.
