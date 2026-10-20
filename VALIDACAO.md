# Validação — 20/10/2026

- Sintaxe PHP 8.3: aprovada para `api.php`.
- Sintaxe JavaScript: aprovada com `node --check`.
- API real, somente leitura: lista encontrada com ID 6; tag encontrada com ID 1. Nenhum contato real foi criado ou modificado durante os testes.
- Backend com API simulada: sessão, CSRF, validação, cadastro, inscrição na lista, aplicação da tag, bloqueio da pesquisa sem cadastro, salvamento das 10 respostas e atualização da mesma nota aprovados.
- Navegador Chromium: cadastro → popup → check-in → 10 perguntas → confirmação aprovado com backend PHP e API simulada. O acesso ao WhatsApp já aparece antes de responder.
- Renderização revisada em desktop 1664 px e celular 390 px, com fonte local. Sem rolagem horizontal; todas as sete imagens carregaram e tiveram seus locais e proporções conferidos.
- Layout baseado no frame desktop 87:65 e mobile 154:2. Os assets são renders dos respectivos elementos originais do Figma, exportados individualmente devido à indisponibilidade dos links de download convencionais. O conteúdo da página permanece HTML editável; nenhuma seção foi substituída por imagem da página.

## Antes de publicar

1. Definir a chave da API no servidor (`AC_API_KEY` ou `private/config.php`). O ZIP não contém a chave real.
2. Informar o convite real do grupo (`WHATSAPP_GROUP_URL` ou configuração). Enquanto estiver vazio, a tela informa que o link será disponibilizado em breve.
3. Executar um cadastro próprio em produção para validar a escrita real e as permissões de contatos, listas, tags e notas do usuário da API. A leitura real foi verificada; a escrita real ainda não.
4. Confirmar as alternativas criadas para sexo/escolaridade/já investe e a grafia “Mim Black”, preservada do PDF.

## Referências técnicas consultadas

- https://developers.activecampaign.com/reference/sync-a-contacts-data
- https://developers.activecampaign.com/reference/update-list-status-for-contact
- https://developers.activecampaign.com/reference/create-a-note

## Atualização de DDIs

245 países/territórios no seletor e 206 códigos internacionais distintos; validação PHP usa a mesma base local. Sintaxe PHP/JS aprovada. Fluxo no Chromium repetido com seleção da Polônia (+48), telefone nacional e confirmação via API simulada.

## Animações

Chromium: animações de entrada verificadas; cancelamento imediato ao ativar movimento reduzido; recarga sem animações e conteúdo visível. Fluxo de cadastro e pesquisa repetido com sucesso após a alteração.

## Visual do check-in

Removido o fundo radial amarelo e o gradiente do card. Fundo azul-escuro uniforme, bordas discretas e hierarquia de textos revisada, com copy preservada. Renderização mobile conferida e fluxo de cadastro/pesquisa aprovado novamente.

## Fundo e bandeiras

Página de obrigada com hero WebP desfocada via CSS e sobreposição azul-escura. Hero, biografia e logo referenciadas como WebP. 245 bandeiras SVG locais em assets, verificadas como XML válido e selecionadas dinamicamente pelo país. Fluxo de cadastro/pesquisa aprovado e fundo revisado em celular.

## Seletor de DDI simplificado

Campo compacto com bandeira e código. Modal de pesquisa por nome, sigla ou DDI, ignorando acentos. Seleção mantém integração com o formulário e validação do servidor.

## Google Sheets

PHP com sintaxe aprovada. Apps Script testado em ambiente simulado para autenticação, cabeçalhos, linha, reenvio sem duplicação e texto seguro. A escrita real no Google Sheets depende da publicação e autorização do Apps Script, ainda pendentes. O fluxo anterior de notas no ActiveCampaign foi substituído pelo webhook da planilha.
