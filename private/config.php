<?php
// Este arquivo deve permanecer FORA da pasta pública do domínio.
return [
    'api_url' => getenv('AC_API_URL') ?: 'https://thaysassisequipe23255.api-us1.com',
    'api_key' => getenv('AC_API_KEY') ?: '77e45c992baa6ea07e735f637b84afa2493d123af148d01e11e73a37a7688297dbba17a9',     'list_name' => 'CPT - 3 LIBERDADES - 09 NOV 26',
    'tag_name' => 'CPT-3L-09-NOV-26',
    'whatsapp_url' => getenv('https://sndflw.com/i/DlpYsVbaM63GsH6UkXZo') ?: '',
    
    'survey_fields' => [],
    'sheets_webhook_url' => getenv('SHEETS_WEBHOOK_URL') ?: 'https://script.google.com/macros/s/AKfycbzttQEKa4Mzc8XpKk303m8Uk3PRmxO4t4_9qqr1s5jz-fu_tiGmRH3xHktRfYcX5fWq/exec',
    'sheets_webhook_token' => getenv('SHEETS_WEBHOOK_TOKEN') ?: 'd7a92f4e8c1b6053a9f0e26d4b87c5136e02a9d5f4c718b3a6e0d8925f1c47ab',
];
