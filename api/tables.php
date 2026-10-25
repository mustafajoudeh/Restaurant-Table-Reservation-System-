<?php
declare(strict_types=1);

require_once __DIR__ . '/../bal/TableBAL.php';
require_once __DIR__ . '/helpers.php';

function tableToArray(TableBAL $table): array
{
    return [
        'tableId' => $table->id,
        'tableNumber' => $table->tableNumber,
        'bookingPrice' => $table->bookingPrice,
        'deposit' => $table->deposit,
        'maxCapacity' => $table->maxCapacity,
        'status' => $table->status,
    ];
}

function handleTablesRequest(
    string $method,
    string $route
): void {
    if ($method !== 'GET') {
        header('Allow: GET, OPTIONS');

        jsonResponse(405, [
            'success' => false,
            'message' => 'Method not allowed.',
        ]);
    }

    if ($route === '/tables') {
        getAllTables();
        return;
    }

    if (preg_match('#^/tables/([1-9][0-9]*)$#', $route, $matches)) {
        getTableById((int) $matches[1]);
        return;
    }

    jsonResponse(404, [
        'success' => false,
        'message' => 'Route not found.',
    ]);
}

function getAllTables(): void
{
    try {
        $tables = TableBAL::getAllTables();

        $result = array_map(
            'tableToArray',
            $tables
        );

        jsonResponse(200, [
            'success' => true,
            'data' => $result,
        ]);
    } catch (Throwable $e) {
        error_log((string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}

function getTableById(int $tableId): void
{
    try {
        $table = TableBAL::findById($tableId);

        if ($table === null) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Table not found.',
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'data' => tableToArray($table),
        ]);
    } catch (Throwable $e) {
        error_log((string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}