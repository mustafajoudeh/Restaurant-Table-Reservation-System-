<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../bal/UserBAL.php';
require_once __DIR__ . '/helpers.php';

function handleUsersRequest(string $route, string $method): void
{
    $route = rtrim($route, '/');

    if ($route === '') {
        $route = '/';
    }

    $knownRoutes = [
        '/api/users/register',
        '/api/users/login',
    ];

    if (!in_array($route, $knownRoutes, true)) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Endpoint not found.',
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed. Use POST.',
        ]);
    }

    if ($route === '/api/users/register') {
        registerUser();
    }

    if ($route === '/api/users/login') {
        loginUser();
    }

    jsonResponse(404, [
        'success' => false,
        'message' => 'Endpoint not found.',
    ]);
}

function registerUser(): void
{
    $data = getJsonBody();

    $usernameValue = $data['username'] ?? null;
    $emailValue = $data['email'] ?? null;
    $passwordValue = $data['password'] ?? null;
    $phoneValue = $data['phoneNumber'] ?? null;

    if (
        !is_string($usernameValue) ||
        !is_string($emailValue) ||
        !is_string($passwordValue)
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Username, email and password must be strings.',
        ]);
    }

    if (
        $phoneValue !== null &&
        !is_string($phoneValue)
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Phone number must be a string or null.',
        ]);
    }

    $username = trim($usernameValue);
    $email = trim($emailValue);
    $password = $passwordValue;

    $phoneNumber = $phoneValue !== null
        ? trim($phoneValue)
        : null;

    if (
        $username === '' ||
        $email === '' ||
        $password === ''
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Username, email and password are required.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid email address.',
        ]);
    }

    if ($phoneNumber === '') {
        $phoneNumber = null;
    }

    // The public registration endpoint always creates a Customer.
    // Any role field supplied by the client is ignored.
    $user = new UserBAL(
        null,
        'Customer',
        $username,
        $email,
        $phoneNumber,
        $password
    );

    if (!$user->save()) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Unable to register user.',
        ]);
    }

    jsonResponse(201, [
        'success' => true,
        'message' => 'User registered successfully.',
        'data' => [
            'userId' => $user->id,
            'role' => 'Customer',
            'username' => $user->username,
            'email' => $user->email,
        ],
    ]);
}

function loginUser(): void
{
    $data = getJsonBody();

    $emailValue = $data['email'] ?? null;
    $passwordValue = $data['password'] ?? null;

    if (
        !is_string($emailValue) ||
        !is_string($passwordValue)
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Email and password must be strings.',
        ]);
    }

    $email = trim($emailValue);
    $password = $passwordValue;

    if ($email === '' || $password === '') {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Email and password are required.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid email address.',
        ]);
    }

    $user = UserBAL::authenticate($email, $password);

    if ($user === null) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Invalid email or password.',
        ]);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

session_regenerate_id(true);

$_SESSION['userId'] = $user->id;
$_SESSION['role'] = $user->role;
$_SESSION['username'] = $user->username;

    jsonResponse(200, [
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'userId' => $user->id,
            'role' => $user->role,
            'username' => $user->username,
            'email' => $user->email,
        ],
    ]);
}