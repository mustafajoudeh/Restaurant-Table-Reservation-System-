<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bal/PaymentBAL.php';
require_once __DIR__ . '/../bal/BookingBAL.php';
require_once __DIR__ . '/../dal/TableDAO.php';
require_once __DIR__ . '/../bal/dto/PaymentCreateDTO.php';

/**
 * GET /api/payments
 * Administrators only.
 */
function getAllPaymentsRequest(): void
{
    requireAdmin();

    jsonResponse(200, [
        'success' => true,
        'data' => PaymentBAL::getAllPayments(),
    ]);
}

/**
 * GET /api/payments/{id}
 * Administrators only.
 */
function getPaymentByIdRequest(int $paymentId): void
{
    requireAdmin();

    if ($paymentId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid payment ID.',
        ]);
    }

    $payment = PaymentBAL::findById($paymentId);

    if ($payment === null) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Payment not found.',
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'data' => $payment,
    ]);
}

/**
 * GET /api/bookings/{id}/payments
 * Administrators only for now.
 */
function getBookingPaymentsRequest(int $bookingId): void
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
        'data' => PaymentBAL::getPaymentsByBookingId($bookingId),
    ]);
}

/**
 * POST /api/payments
 * Creates a pending deposit payment record.
 *
 * This does not process a real payment.
 */
function createPaymentRequest(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $data = getJsonBody();

    foreach (['bookingId', 'paymentMethod'] as $field) {
        if (!array_key_exists($field, $data)) {
            jsonResponse(400, [
                'success' => false,
                'message' => "Missing required field: {$field}.",
            ]);
        }
    }

    if (
        !is_int($data['bookingId']) &&
        !(
            is_string($data['bookingId']) &&
            ctype_digit($data['bookingId'])
        )
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'bookingId must be an integer.',
        ]);
    }

    if (!is_string($data['paymentMethod'])) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'paymentMethod must be a string.',
        ]);
    }

    $bookingId = (int) $data['bookingId'];

    if ($bookingId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid booking ID.',
        ]);
    }

    /*
     * Read the currently logged-in user, if any.
     */
    $sessionUserId = (
        isset($_SESSION['userId']) &&
        is_numeric($_SESSION['userId']) &&
        (int) $_SESSION['userId'] > 0
    )
        ? (int) $_SESSION['userId']
        : null;

    /*
     * Guest booking permission must have been created by the server
     * when the guest booking was successfully created.
     */
    $guestExpiry =
        $_SESSION['guest_booking_ids'][(string) $bookingId] ?? null;

    $hasGuestSessionAccess =
        is_numeric($guestExpiry) &&
        (int) $guestExpiry >= time();

    if ($sessionUserId === null && !$hasGuestSessionAccess) {
        jsonResponse(401, [
            'success' => false,
            'message' =>
                'Sign in or use the same browser session that created this guest booking.',
        ]);
    }

    $booking = BookingBAL::findById($bookingId);

    if ($booking === null || $booking === false) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Booking not found.',
        ]);
    }

    /*
     * Registered booking: current user must own it.
     * Guest booking: UserID must be NULL and the current session
     * must have the corresponding guest booking permission.
     */
    $ownsRegisteredBooking =
        $sessionUserId !== null &&
        $booking->userId !== null &&
        (int) $booking->userId === $sessionUserId;

    $ownsGuestBooking =
        $booking->userId === null &&
        $hasGuestSessionAccess;

    if (!$ownsRegisteredBooking && !$ownsGuestBooking) {
        jsonResponse(403, [
            'success' => false,
            'message' =>
                'You are not authorized to create a payment for this booking.',
        ]);
    }

    if (
        strcasecmp((string) $booking->status, 'Cancelled') === 0
    ) {
        jsonResponse(409, [
            'success' => false,
            'message' => 'Cannot pay for a cancelled booking.',
        ]);
    }

    /*
     * The deposit amount comes from the database, not the browser.
     */
    $table = TableDAO::getTableById($booking->tableId);

    if ($table === null) {
        jsonResponse(409, [
            'success' => false,
            'message' => 'The booking table could not be found.',
        ]);
    }

    $amount = $table->deposit;

    if (
        !is_numeric($amount) ||
        (float) $amount <= 0 ||
        (float) $amount > 999.99
    ) {
        jsonResponse(409, [
            'success' => false,
            'message' => 'The table deposit is invalid.',
        ]);
    }

    $paymentMethod = trim($data['paymentMethod']);

    if (!in_array($paymentMethod, ['Cash', 'Online'], true)) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'paymentMethod must be Cash or Online.',
        ]);
    }

    try {
        $reference = 'PAY-' . bin2hex(random_bytes(16));

        $paymentDTO = new PaymentCreateDTO(
            $bookingId,
            'Deposit',
            number_format((float) $amount, 2, '.', ''),
            $paymentMethod,
            $reference,
            'Pending'
        );

        $paymentId = PaymentBAL::createPayment($paymentDTO);
        $payment = PaymentBAL::findById($paymentId);

        jsonResponse(201, [
            'success' => true,
            'message' => $paymentMethod === 'Cash'
                ? 'Pending cash deposit record created. An administrator must confirm receipt of cash.'
                : 'Pending online demo payment record created. No money has been charged.',
            'data' => $payment,
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(400, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (PDOException $e) {
        error_log(
            'Payment creation database error: ' . (string) $e
        );

        jsonResponse(409, [
            'success' => false,
            'message' => 'The payment record could not be created.',
        ]);

    } catch (Throwable $e) {
        error_log('Payment creation error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}

/**
 * POST /api/payments/{id}/confirm-cash
 * Administrators only.
 */
function confirmCashPaymentRequest(int $paymentId): void
{
    requireAdmin();

    if ($paymentId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid payment ID.',
        ]);
    }

    try {
        $confirmed = PaymentBAL::confirmCashPayment($paymentId);

        if (!$confirmed) {
            jsonResponse(409, [
                'success' => false,
                'message' => 'Payment not found or could not be confirmed.',
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'message' => 'Cash payment confirmed successfully.',
            'data' => PaymentBAL::findById($paymentId),
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(409, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (Throwable $e) {
        error_log('Cash payment confirmation error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}

/**
 * POST /api/payments/{id}/simulate-online
 * Demo-only payment simulation.
 */
function simulateOnlinePaymentRequest(int $paymentId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($paymentId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid payment ID.',
        ]);
    }

    $payment = PaymentBAL::findById($paymentId);

    if ($payment === null || $payment === false) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Payment not found.',
        ]);
    }

    $booking = BookingBAL::findById($payment->bookingId);

    if ($booking === null || $booking === false) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Booking not found.',
        ]);
    }

    $sessionUserId = (
        isset($_SESSION['userId']) &&
        is_numeric($_SESSION['userId']) &&
        (int) $_SESSION['userId'] > 0
    )
        ? (int) $_SESSION['userId']
        : null;

    /*
     * A guest is authorized only if the booking was granted
     * payment access in this same server-side PHP session.
     */
    $guestExpiry =
        $_SESSION['guest_booking_ids'][
            (string) $payment->bookingId
        ] ?? null;

    $hasGuestSessionAccess =
        is_numeric($guestExpiry) &&
        (int) $guestExpiry >= time();

    $ownsRegisteredBooking =
        $sessionUserId !== null &&
        $booking->userId !== null &&
        (int) $booking->userId === $sessionUserId;

    $ownsGuestBooking =
        $booking->userId === null &&
        $hasGuestSessionAccess;

    if (!$ownsRegisteredBooking && !$ownsGuestBooking) {
        jsonResponse(
            $sessionUserId === null ? 401 : 403,
            [
                'success' => false,
                'message' =>
                    'You are not authorized to simulate payment for this booking.',
            ]
        );
    }

    /*
     * Only accept the existing demo simulation results.
     */
    $data = getJsonBody();

    if (
        !isset($data['result']) ||
        !is_string($data['result']) ||
        !in_array(
            $data['result'],
            ['success', 'failure'],
            true
        )
    ) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'result must be success or failure.',
        ]);
    }

    $result = $data['result'] === 'success'
        ? 'Completed'
        : 'Failed';

    try {
        $updated = PaymentBAL::simulateOnlinePayment(
            $paymentId,
            $result
        );

        if (!$updated) {
            jsonResponse(409, [
                'success' => false,
                'message' =>
                    'Payment could not be simulated. It may no longer be pending.',
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'message' =>
                'Demo payment simulation completed. No real money was charged.',
            'data' => PaymentBAL::findById($paymentId),
            'demoOnly' => true,
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(409, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (Throwable $e) {
        error_log(
            'Online payment simulation error: ' . (string) $e
        );

        jsonResponse(500, [
            'success' => false,
            'message' => 'An internal server error occurred.',
        ]);
    }
}

/**
 * POST /api/payments/{id}/confirm-refund
 * Administrators only.
 *
 * Confirms a previously created pending refund.
 */
function confirmRefundRequest(int $paymentId): void
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

    if (
        !isset($_SESSION['role']) ||
        strtolower((string) $_SESSION['role']) !== 'admin'
    ) {
        jsonResponse(403, [
            'success' => false,
            'message' => 'Admin access required.',
        ]);
    }

    if ($paymentId <= 0) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid payment ID.',
        ]);
    }

    try {
        $success = PaymentBAL::confirmRefund($paymentId);

        if (!$success) {
            jsonResponse(409, [
                'success' => false,
                'message' => 'Refund not found, or it is not pending.',
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'message' => 'Refund marked as completed.',
        ]);

    } catch (InvalidArgumentException $e) {
        jsonResponse(409, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);

    } catch (Throwable $e) {
        error_log('Confirm refund error: ' . (string) $e);

        jsonResponse(500, [
            'success' => false,
            'message' => 'Unable to confirm refund.',
        ]);
    }
}