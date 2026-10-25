<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bal/BookingBAL.php';
require_once __DIR__ . '/../bal/dto/BookingUpdateDTO.php';

/**
 * Convert database booking errors into safe API responses.
 */
function handleBookingDatabaseError(
    PDOException $e,
    string $operation
): void {
    $sqlState = (string) (
        $e->errorInfo[0] ?? $e->getCode()
    );

    $databaseMessage = (string) (
        $e->errorInfo[2] ?? ''
    );

    if ($sqlState === '45000') {
        error_log(
            "Booking {$operation} rejected: " . $databaseMessage
        );

        $message = 'The booking could not be processed.';

        if (
            strpos(
                $databaseMessage,
                'already booked during the selected time'
            ) !== false
        ) {
            $message =
                'This table is already booked during the selected time.';
        } elseif (
            strpos(
                $databaseMessage,
                'exceeds table capacity'
            ) !== false
        ) {
            $message =
                'The number of guests exceeds table capacity.';
        } elseif (
            strpos(
                $databaseMessage,
                'does not exist'
            ) !== false
        ) {
            $message = 'The selected table does not exist.';
        } elseif (
            strpos(
                $databaseMessage,
                'Invalid booking details'
            ) !== false
        ) {
            $message = 'Invalid booking details or time range.';
        }

        jsonResponse(409, [
            'success' => false,
            'message' => $message,
        ]);
    }

    error_log(
        "Booking {$operation} database error: " . (string) $e
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An internal server error occurred.',
    ]);
}

/**
 * GET /api/bookings
 * Administrators only.
 */
function getAllBookings(): void
{
    requireAdmin();

    $bookings = BookingBAL::getAllBookings();

    jsonResponse(200, [
        'success' => true,
        'data' => $bookings,
    ]);
}

/**
 * GET /api/bookings/{id}
 * Administrators only.
 */
function getBookingById(int $bookingId): void
{
    requireAdmin();

    if ($bookingId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid booking ID.',
        ]);
    }

    $booking = BookingBAL::findById($bookingId);

    if ($booking === null) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Booking not found.',
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'data' => $booking,
    ]);
}

/**
 * POST /api/bookings
 *
 * Authenticated users can book as before.
 * Guests must verify their email through Email OTP.
 */
function createBookingRequest(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $data = getJsonBody();

    /*
     * If guest details are submitted, use the guest booking flow.
     * This prevents a stale Admin session from assigning the Admin ID
     * to an OTP-verified guest booking.
     */
    $hasGuestDetails =
        array_key_exists('guestName', $data) ||
        array_key_exists('guestEmail', $data) ||
        array_key_exists('guestPhone', $data);

    $isGuestBooking = $hasGuestDetails;

    $isAuthenticated = !$isGuestBooking
        && isset($_SESSION['userId'])
        && is_numeric($_SESSION['userId'])
        && (int) $_SESSION['userId'] > 0;

    $guestName = '';
    $guestEmail = '';
    $guestPhone = null;

    /*
     * Validate guest information and OTP verification.
     */
    if ($isGuestBooking) {
        $guestNameInput = $data['guestName'] ?? null;
        $guestEmailInput = $data['guestEmail'] ?? null;
        $guestPhoneInput = $data['guestPhone'] ?? '';

        if (
            !is_string($guestNameInput) ||
            !is_string($guestEmailInput) ||
            !is_string($guestPhoneInput)
        ) {
            jsonResponse(400, [
                'success' => false,
                'message' => 'Invalid guest contact details.',
            ]);
        }

        $guestName = trim($guestNameInput);
        $guestEmail = strtolower(trim($guestEmailInput));
        $guestPhone = trim($guestPhoneInput);

        $verifiedEmail = strtolower(
            (string) ($_SESSION['guest_email_verified'] ?? '')
        );

        if (
            $verifiedEmail === '' ||
            $guestEmail === '' ||
            !hash_equals($verifiedEmail, $guestEmail)
        ) {
            jsonResponse(403, [
                'success' => false,
                'message' =>
                    'Verify your email before creating a guest booking.',
            ]);
        }

        if (
            $guestName === '' ||
            mb_strlen($guestName) > 150 ||
            !filter_var($guestEmail, FILTER_VALIDATE_EMAIL) ||
            mb_strlen($guestPhone) > 30
        ) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Invalid guest contact details.',
            ]);
        }

        if ($guestPhone === '') {
            $guestPhone = null;
        }
    }

    /*
     * Validate booking fields.
     */
    $requiredFields = [
        'tableId',
        'startDateTime',
        'endDateTime',
        'numberOfIndividuals',
        'numberOfChildren',
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            jsonResponse(400, [
                'success' => false,
                'message' => "Missing required field: {$field}.",
            ]);
        }
    }

    foreach (
        ['tableId', 'numberOfIndividuals', 'numberOfChildren']
        as $field
    ) {
        if (
            !is_int($data[$field]) &&
            !(
                is_string($data[$field]) &&
                ctype_digit($data[$field])
            )
        ) {
            jsonResponse(400, [
                'success' => false,
                'message' => "{$field} must be an integer.",
            ]);
        }
    }

    if (
        !is_string($data['startDateTime']) ||
        !is_string($data['endDateTime'])
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' =>
                'Start and end date-time values must be strings.',
        ]);
    }

    $bookingDTO = new BookingCreateDTO(
        (int) $data['tableId'],
        $data['startDateTime'],
        $data['endDateTime'],
        (int) $data['numberOfIndividuals'],
        (int) $data['numberOfChildren']
    );

    /*
     * A guest booking must use NULL as userId, even if an Admin
     * or Customer is still stored in this browser's PHP session.
     */
    $userId = $isAuthenticated
        ? (int) $_SESSION['userId']
        : null;

    try {
        $bookingId = BookingBAL::createBooking(
            $bookingDTO,
            $userId
        );

        if ($isGuestBooking) {
            BookingBAL::saveGuestDetails(
                $bookingId,
                $guestName,
                $guestEmail,
                $guestPhone
            );

            /*
             * Store guest payment authorization in the server-side
             * session. The browser cannot set this session value directly.
             */
            $now = time();

            if (
                !isset($_SESSION['guest_booking_ids']) ||
                !is_array($_SESSION['guest_booking_ids'])
            ) {
                $_SESSION['guest_booking_ids'] = [];
            }

            /*
             * Remove expired guest booking permissions.
             */
            foreach (
                $_SESSION['guest_booking_ids'] as $existingId => $expiresAt
            ) {
                if (
                    !is_numeric($expiresAt) ||
                    (int) $expiresAt < $now
                ) {
                    unset(
                        $_SESSION['guest_booking_ids'][$existingId]
                    );
                }
            }

            /*
             * Allow payment for this guest booking for 24 hours
             * in the same PHP session.
             */
            $_SESSION['guest_booking_ids'][(string) $bookingId]
                = $now + 86400;

            /*
             * Prevent reusing the verified email to create another
             * guest booking without a new OTP verification.
             */
            unset($_SESSION['guest_email_verified']);
        }

        $responseData = [
            'bookingId' => $bookingId,
            'tableId' => $bookingDTO->tableId,
            'userId' => $userId,
            'status' => 'Pending',
            'numberOfIndividuals' =>
                $bookingDTO->numberOfIndividuals,
            'numberOfChildren' =>
                $bookingDTO->numberOfChildren,
            'startDateTime' =>
                $bookingDTO->startDateTime,
            'endDateTime' =>
                $bookingDTO->endDateTime,
        ];

        if ($isGuestBooking) {
            $responseData['guest'] = [
                'name' => $guestName,
                'email' => $guestEmail,
                'phone' => $guestPhone,
            ];
        }

        jsonResponse(201, [
            'success' => true,
            'message' => 'Booking created successfully.',
            'data' => $responseData,
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(400, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (PDOException $e) {
        handleBookingDatabaseError($e, 'creation');

    } catch (Throwable $e) {
        error_log('Booking creation error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}
/**
 * PUT /api/bookings/{id}
 * Update an existing booking.
 * Administrators only.
 */
function updateBookingRequest(int $bookingId): void
{
    requireAdmin();

    if ($bookingId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid booking ID.',
        ]);
    }

    $data = getJsonBody();

    $requiredFields = [
        'tableId',
        'startDateTime',
        'endDateTime',
        'numberOfIndividuals',
        'numberOfChildren',
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            jsonResponse(400, [
                'success' => false,
                'message' => "Missing required field: {$field}.",
            ]);
        }
    }

    foreach (
        ['tableId', 'numberOfIndividuals', 'numberOfChildren']
        as $field
    ) {
        if (
            !is_int($data[$field]) &&
            !(
                is_string($data[$field]) &&
                ctype_digit($data[$field])
            )
        ) {
            jsonResponse(400, [
                'success' => false,
                'message' => "{$field} must be an integer.",
            ]);
        }
    }

    if (
        !is_string($data['startDateTime']) ||
        !is_string($data['endDateTime'])
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Date-time values must be strings.',
        ]);
    }

    $bookingDTO = new BookingUpdateDTO(
        (int) $data['tableId'],
        $data['startDateTime'],
        $data['endDateTime'],
        (int) $data['numberOfIndividuals'],
        (int) $data['numberOfChildren']
    );

    try {
        $updated = BookingBAL::updateBooking(
            $bookingId,
            $bookingDTO
        );

        if (!$updated) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Booking not found or update failed.',
            ]);
        }

        $booking = BookingBAL::findById($bookingId);

        if ($booking === null) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Booking not found after update.',
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'message' => 'Booking updated successfully.',
            'data' => $booking,
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(400, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (PDOException $e) {
        handleBookingDatabaseError($e, 'update');

    } catch (Throwable $e) {
        error_log('Booking update error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}

/**
 * POST /api/bookings/{id}/cancel
 *
 * A user may cancel their own booking.
 * An administrator may cancel any booking.
 */
function cancelBookingRequest(int $bookingId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (
        !isset($_SESSION['userId']) ||
        !is_numeric($_SESSION['userId']) ||
        (int) $_SESSION['userId'] <= 0
    ) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Authentication required.',
        ]);
    }

    if ($bookingId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid booking ID.',
        ]);
    }

    try {
        $booking = BookingBAL::findById($bookingId);

        if ($booking === null || $booking === false) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Booking not found.',
            ]);
        }

        $isAdmin = isset($_SESSION['role'])
            && strtolower((string) $_SESSION['role']) === 'admin';

        if (
            !$isAdmin &&
            (
                $booking->userId === null ||
                (int) $booking->userId !== (int) $_SESSION['userId']
            )
        ) {
            jsonResponse(403, [
                'success' => false,
                'message' =>
                    'You cannot cancel another user\'s booking.',
            ]);
        }

        $result = BookingBAL::cancelBooking($bookingId);

        if (
            !is_array($result) ||
            !array_key_exists('success', $result)
        ) {
            error_log(
                'BookingBAL::cancelBooking returned an unexpected result.'
            );

            jsonResponse(500, [
                'success' => false,
                'message' => 'An internal server error occurred.',
            ]);
        }

        if (!$result['success']) {
            $code = $result['code'] ?? 'ERROR';

            $statusCode = match ($code) {
                'INVALID_ID' => 400,
                'NOT_FOUND' => 404,
                'INVALID_STATUS' => 409,
                default => 409,
            };

            $message = match ($code) {
                'INVALID_ID' => 'Invalid booking ID.',
                'NOT_FOUND' => 'Booking not found.',
                'INVALID_STATUS' =>
                    'This booking cannot be cancelled.',
                default => 'Unable to cancel booking.',
            };

            jsonResponse($statusCode, [
                'success' => false,
                'message' => $message,
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'message' => 'Booking cancelled successfully.',
            'refundRequestCreated' =>
                $result['refundCreated'] ?? false,
        ]);

    } catch (PDOException $e) {
        handleBookingDatabaseError($e, 'cancel booking');

    } catch (Throwable $e) {
        error_log('Cancel booking error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' =>
                'An unexpected error occurred while cancelling the booking.',
        ]);
    }
}