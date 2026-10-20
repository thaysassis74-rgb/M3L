<?php
// Copie como config.php apenas no servidor. Não publique as credenciais.
return [
    'api_url' => getenv('AC_API_URL') ?: 'https://thaysassisequipe23255.api-us1.com',
    'api_key' => getenv('AC_API_KEY') ?: '',
    'list_name' => 'CPT - 3 LIBERDADES - 09 NOV 26',
    'tag_name' => 'CPT-3L-09-NOV-26',
    'list_id' => '6',
    'tag_id' => '1',
    'whatsapp_url' => getenv('WHATSAPP_GROUP_URL') ?: 'https://sndflw.com/i/DlpYsVbaM63GsH6UkXZo',
    'sheets_webhook_url' => getenv('SHEETS_WEBHOOK_URL') ?: '',
    'sheets_webhook_token' => getenv('SHEETS_WEBHOOK_TOKEN') ?: '',
];
