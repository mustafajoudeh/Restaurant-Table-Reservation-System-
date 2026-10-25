<?php
declare(strict_types=1);

return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'username'   => getenv('SMTP_USERNAME') ?: '',
    'password'   => getenv('SMTP_PASSWORD') ?: '',
    'from_email' => getenv('SMTP_USERNAME') ?: '',
    'from_name'  => 'Restaurant Reservations',
];