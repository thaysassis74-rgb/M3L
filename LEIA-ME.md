# 3 Liberdades — Thays Assis

Implementação independente em HTML, CSS, JavaScript e PHP 8.1+, sem compilação, WordPress ou Elementor. Requer PHP com cURL, sessões e saída HTTPS para o ActiveCampaign.

## Instalação

1. Use `public/` como raiz pública do domínio. Em hospedagem compartilhada, copie **o conteúdo** de `public/` para `public_html/` e coloque `private/` ao lado de `public_html/`, fora dela. O `api.php` já procura `../private/config.php`.
2. Em `private/config.php`, preencha `api_key` com a chave informada e `whatsapp_url` com o convite do grupo. Alternativamente configure `AC_API_KEY` e `WHATSAPP_GROUP_URL` como variáveis de ambiente do PHP. A URL da API já está definida. Nenhum segredo é entregue ao navegador.
3. Confirme a existência da lista **CPT - 3 LIBERDADES - 09 NOV 26** e da tag **CPT-3L-09-NOV-26**. A API procura pelo nome exato, com paginação, e não cria nem escolhe listas parecidas.
4. Ative HTTPS e teste com um contato seu: confirme nome, telefone, lista e tag no ActiveCampaign; responda a pesquisa e confira a nota; teste o convite do WhatsApp.

Para desenvolvimento: `php -S localhost:8080 -t public` a partir desta pasta.

## Fluxo

O cadastro faz upsert por e-mail, inscreve na lista e aplica a tag. Só após todas essas etapas mostra o popup de cadastro concluído. O botão abre `obrigado.html`, com pesquisa de 10 perguntas e botão do WhatsApp independente da conclusão. O convite abre em nova aba; não há redirecionamento automático. A tela final confirma o salvamento real das respostas.

Contatos com inscrição cancelada ou devolução não são reinscritos automaticamente. Reenvios recuperam o mesmo contato e verificam os vínculos existentes. A pesquisa usa a identidade da sessão, sem expor ID de contato na URL. O e-mail da pergunta 1 é salvo como resposta na nota e não altera o e-mail original do cadastro.

As respostas vão para uma **nota no contato**, identificada pela tag do evento. Para segmentar com campos personalizados, preencha `survey_fields` com os IDs existentes, por exemplo `[2 => '12', 3 => '13']` (pergunta → ID). Não são criados campos automaticamente. Reenvios na mesma sessão atualizam a nota existente.

O PDF não especifica alternativas para sexo, idade, escolaridade e a pergunta “já investe”; foram usadas opções usuais e idade numérica. As demais alternativas seguem o PDF, com correções ortográficas. As faixas de tempo sobrepostas e a grafia “Mim Black” foram preservadas para revisão da equipe.

## Design e arquivos

- `index.html`: página de captura, versão V3 (87:65).
- `styles.css`: estilos e composição mobile baseada no frame 154:2.
- `app.js`: formulário, confirmação e pesquisa.
- `obrigado.html`: check-in seguindo o fluxo do PDF e a identidade do 3 Liberdades.
- `api.php`: integração de servidor, validação e CSRF.
- `assets/`: imagens e ícones originais exportados do Figma; sem URLs temporárias.
- `private/config.php`: configuração de servidor.

Fonte Manrope local, com licença incluída; não depende do Google Fonts. A página de obrigado usa as referências do PDF como fluxo e hierarquia; cores e logo são do 3 Liberdades. O Figma não apresenta um frame próprio dessa página.

## Operação e limites

Não publique a pasta `private`, o ZIP ou a chave da API. Este pacote não contém a chave real: ela deve ser inserida no servidor. O formulário tem honeypot, validação no servidor, token CSRF e limite por sessão. Para divulgação pública, configure limitação de requisições por IP no provedor/CDN; uma sessão nova contorna o limite local. Os logs registram somente a operação e o código de erro, sem dados do contato ou chave.

Não inclui pixels, política de privacidade, CAPTCHA ou automações além da lista/tag solicitadas. O aviso educacional e a copy da captura foram mantidos do Figma.

## Verificação

Consulte `VALIDACAO.md` para os testes executados e as pendências de configuração. Nenhum contato real de teste é enviado automaticamente.

## DDIs internacionais

Seletor com 245 países e territórios, nomes em português e códigos internacionais. Base libphonenumber-js 1.13.15. Lista local em `assets/countries.json`, usada também para validar o DDI no servidor. Países que compartilham +1 ou +7 aparecem separadamente. O usuário digita o número nacional com o código de área; a API concatena o DDI uma única vez.

## Animações suaves

Entradas com fade e deslocamento de 14 px em 650 ms, com intervalo de 90 ms entre os elementos da hero. Foto e textos da biografia aparecem uma única vez ao entrar na área visível. Botão com elevação discreta no hover de desktop e resposta ao clique. Sem bibliotecas adicionais ou movimento contínuo. A preferência `prefers-reduced-motion` cancela os efeitos, inclusive se alterada com a página aberta; o conteúdo permanece acessível sem JavaScript.

## Destino atualizado da pesquisa

As respostas agora são enviadas à planilha Pesquisa. Siga `integracao/CONFIGURAR-PESQUISA.md`. A implantação do Apps Script e a configuração de URL/token são obrigatórias para ativar o envio. Esta seção substitui as instruções anteriores sobre notas e campos da pesquisa no ActiveCampaign.
