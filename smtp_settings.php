<?php
$SMTP_SETTINGS = [
    'host' => getenv('MAIL_HOST') ?: 'smtp.gmail.com',
    'port' => (int)(getenv('MAIL_PORT') ?: 587),
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_APP_PASSWORD') ?: '',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
    'from_email' => getenv('MAIL_FROM_EMAIL') ?: (getenv('MAIL_USERNAME') ?: ''),
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'Six Origins',
];