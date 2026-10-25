<?php
declare(strict_types=1);

function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        echo '{"success":false,"message":"Failed to encode response."}';
        exit;
    }

    echo $json;
    exit;
}

function getJsonBody(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        throw new InvalidArgumentException(
            'Request body is required.'
        );
    }

    try {
        $decoded = json_decode(
            $rawBody,
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        throw new InvalidArgumentException(
            'Invalid JSON format.'
        );
    }

    if (!is_object($decoded)) {
        throw new InvalidArgumentException(
            'JSON body must be an object.'
        );
    }

    $data = (array) $decoded;

    if ($data === []) {
        throw new InvalidArgumentException(
            'JSON body must not be empty.'
        );
    }

    return $data;
}

function requireAdmin(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Is the user logged in?
    if (!isset($_SESSION['userId'])) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Authentication required.',
        ]);
    }

    // Does the logged-in user have Admin privileges?
    if (($_SESSION['role'] ?? null) !== 'Admin') {
        jsonResponse(403, [
            'success' => false,
            'message' => 'Access denied. Admin only.',
        ]);
    }
}