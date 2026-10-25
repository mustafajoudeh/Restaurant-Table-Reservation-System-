<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    echo json_encode([
        'success' => false,
        'message' => 'Please use the sign-out button.'
    ]);

    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Clear the current PHP session data.
$_SESSION = [];

// Remove the session cookie from the browser.
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie['path'],
        $cookie['domain'],
        (bool) $cookie['secure'],
        (bool) $cookie['httponly']
    );
}

// Destroy the server-side session.
session_destroy();

echo json_encode([
    'success' => true,
    'message' => 'You have been signed out.'
]);