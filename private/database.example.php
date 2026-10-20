<?php
// Copie como database.php; preencha apenas no servidor, fora da pasta public.
return [
    'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=m3l;charset=utf8mb4',
    'user' => getenv('DB_USER') ?: 'm3l',
    'password' => getenv('DB_PASSWORD') ?: '',
];
