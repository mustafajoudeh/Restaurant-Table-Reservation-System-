<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/bookings.php';
require_once __DIR__ . '/payments.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsedPath = parse_url($requestUri, PHP_URL_PATH);

    $requestPath = is_string($parsedPath)
        ? rawurldecode($parsedPath)
        : '/';

    $requestPath = str_replace('\\', '/', $requestPath);

    $scriptName = rawurldecode(
        str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '')
    );

    $basePath = rtrim(dirname($scriptName), '/');

    if ($basePath === '.') {
        $basePath = '';
    }

    if (
        $basePath !== '' &&
        strpos($requestPath, $basePath . '/') === 0
    ) {
        $requestPath = substr($requestPath, strlen($basePath));
    }

    $route = '/';

    $indexPosition = strrpos($requestPath, '/index.php');

    if ($indexPosition !== false) {
        $route = substr(
            $requestPath,
            $indexPosition + strlen('/index.php')
        );
    } else {
        $apiPosition = strpos($requestPath, '/api/');

        if ($apiPosition !== false) {
            $route = substr($requestPath, $apiPosition);
        } elseif (
            substr($basePath, -4) === '/api' &&
            isset($requestPath[0]) &&
            $requestPath[0] === '/'
        ) {
            $route = '/api' . $requestPath;
        } else {
            $route = $requestPath;
        }
    }

    if (!is_string($route) || $route === '') {
        $route = '/';
    }

    $route = rtrim($route, '/');

    if ($route === '') {
        $route = '/';
    }

    // Handle CORS preflight requests.
    if ($method === 'OPTIONS') {
        header('Allow: GET, POST, PUT, OPTIONS');
        http_response_code(204);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize resource routes
    |--------------------------------------------------------------------------
    */

    $tablesRoute = $route;
    $bookingsRoute = $route;
    $paymentsRoute = $route;

    if (strpos($tablesRoute, '/api/tables') === 0) {
        $tablesRoute = substr($tablesRoute, 4);
    }

    if (strpos($bookingsRoute, '/api/bookings') === 0) {
        $bookingsRoute = substr($bookingsRoute, 4);
    }

    if (strpos($paymentsRoute, '/api/payments') === 0) {
        $paymentsRoute = substr($paymentsRoute, 4);
    }

    /*
    |--------------------------------------------------------------------------
    | Tables API
    |--------------------------------------------------------------------------
    */

    if (
        $tablesRoute === '/tables' ||
        preg_match('#^/tables/[1-9][0-9]*$#', $tablesRoute)
    ) {
        handleTablesRequest($method, $tablesRoute);
    }

    /*
    |--------------------------------------------------------------------------
    | Bookings API
    |--------------------------------------------------------------------------
    */

    // POST /api/bookings/{id}/cancel
    if (
        preg_match(
            '#^/bookings/([1-9][0-9]*)/cancel$#',
            $bookingsRoute,
            $matches
        )
    ) {
        if ($method === 'POST') {
            cancelBookingRequest((int) $matches[1]);
        }

        header('Allow: POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // GET /api/bookings
    // POST /api/bookings
    if ($bookingsRoute === '/bookings') {
        if ($method === 'GET') {
            getAllBookings();
        }

        if ($method === 'POST') {
            createBookingRequest();
        }

        header('Allow: GET, POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // GET /api/bookings/{id}
    // PUT /api/bookings/{id}
    if (
        preg_match(
            '#^/bookings/([1-9][0-9]*)$#',
            $bookingsRoute,
            $matches
        )
    ) {
        $bookingId = (int) $matches[1];

        if ($method === 'GET') {
            getBookingById($bookingId);
        }

        if ($method === 'PUT') {
            updateBookingRequest($bookingId);
        }

        header('Allow: GET, PUT, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Payments API
    |--------------------------------------------------------------------------
    */

    // GET /api/payments
    // POST /api/payments
    if ($paymentsRoute === '/payments') {
        if ($method === 'GET') {
            getAllPaymentsRequest();
        }

        if ($method === 'POST') {
            createPaymentRequest();
        }

        header('Allow: GET, POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // POST /api/payments/{id}/confirm-cash
    if (
        preg_match(
            '#^/payments/([1-9][0-9]*)/confirm-cash$#',
            $paymentsRoute,
            $matches
        )
    ) {
        if ($method === 'POST') {
            confirmCashPaymentRequest((int) $matches[1]);
        }

        header('Allow: POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // POST /api/payments/{id}/simulate-online
    if (
        preg_match(
            '#^/payments/([1-9][0-9]*)/simulate-online$#',
            $paymentsRoute,
            $matches
        )
    ) {
        if ($method === 'POST') {
            simulateOnlinePaymentRequest((int) $matches[1]);
        }

        header('Allow: POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // POST /api/payments/{id}/confirm-refund
    if (
        preg_match(
            '#^/payments/([1-9][0-9]*)/confirm-refund$#',
            $paymentsRoute,
            $matches
        )
    ) {
        if ($method === 'POST') {
            confirmRefundRequest((int) $matches[1]);
        }

        header('Allow: POST, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // GET /api/payments/{id}
    if (
        preg_match(
            '#^/payments/([1-9][0-9]*)$#',
            $paymentsRoute,
            $matches
        )
    ) {
        if ($method === 'GET') {
            getPaymentByIdRequest((int) $matches[1]);
        }

        header('Allow: GET, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    // GET /api/bookings/{id}/payments
    if (
        preg_match(
            '#^/bookings/([1-9][0-9]*)/payments$#',
            $bookingsRoute,
            $matches
        )
    ) {
        if ($method === 'GET') {
            getBookingPaymentsRequest((int) $matches[1]);
        }

        header('Allow: GET, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed for this endpoint.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Users API
    |--------------------------------------------------------------------------
    */

    handleUsersRequest($route, $method);

    jsonResponse(404, [
        'success' => false,
        'message' => 'Endpoint not found.',
    ]);

} catch (UserConflictException $e) {
    jsonResponse(409, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (InvalidArgumentException $e) {
    jsonResponse(400, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (Throwable $e) {
    error_log((string) $e);

    jsonResponse(500, [
        'success' => false,
        'message' => 'An internal server error occurred.',
    ]);
}