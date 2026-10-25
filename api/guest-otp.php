<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Return a JSON response and terminate the request.
 */
function otpJson(int $status, array $data): void
{
    http_response_code($status);

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

/**
 * Read and validate the incoming JSON request body.
 */
function otpInput(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        otpJson(400, [
            'success' => false,
            'message' => 'Request body is required.',
        ]);
    }

    try {
        $body = json_decode(
            $rawBody,
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        otpJson(400, [
            'success' => false,
            'message' => 'Invalid JSON format.',
        ]);
    }

    if (!is_object($body)) {
        otpJson(400, [
            'success' => false,
            'message' => 'JSON body must be an object.',
        ]);
    }

    return (array) $body;
}

/**
 * Send a six-digit OTP to a guest's email address.
 *
 * Request:
 * {
 *     "action": "send",
 *     "email": "guest@example.com"
 * }
 */
function sendGuestOtp(string $email): void
{
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        otpJson(422, [
            'success' => false,
            'message' => 'Invalid email address.',
        ]);
    }

    /*
     * Limit OTP requests to one per 60 seconds per PHP session.
     * This timestamp remains after verification, preventing
     * immediate repeated requests after successfully verifying.
     */
    $lastSentAt = (int) (
        $_SESSION['guest_otp_last_sent_at'] ?? 0
    );

    if (time() - $lastSentAt < 60) {
        otpJson(429, [
            'success' => false,
            'message' => 'Please wait 60 seconds before requesting another code.',
        ]);
    }

    $config = require __DIR__ . '/../config/mail.php';

    if (
        empty($config['host']) ||
        empty($config['port']) ||
        empty($config['username']) ||
        empty($config['password']) ||
        empty($config['from_email']) ||
        empty($config['from_name'])
    ) {
        otpJson(500, [
            'success' => false,
            'message' => 'Email configuration is incomplete.',
        ]);
    }

    /*
     * Invalidate any previous OTP and email verification before
     * starting a new verification attempt.
     */
    unset(
        $_SESSION['guest_otp'],
        $_SESSION['guest_email_verified']
    );

    /*
     * Record the request time before sending to prevent repeated
     * SMTP attempts if email delivery fails.
     */
    $_SESSION['guest_otp_last_sent_at'] = time();

    $code = (string) random_int(100000, 999999);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $config['port'];

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Restaurant Reservation - Email Verification';

        $escapedCode = htmlspecialchars(
            $code,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $mail->Body = '
            <div style="font-family: Arial, sans-serif; color: #333;">
                <h2>Email Verification</h2>

                <p>
                    Use the following verification code to verify
                    your email address for your restaurant reservation.
                </p>

                <h1 style="letter-spacing: 6px; color: #526044;">
                    ' . $escapedCode . '
                </h1>

                <p>This code expires in 5 minutes.</p>

                <p>
                    If you did not request this code, you can ignore
                    this email.
                </p>
            </div>
        ';

        $mail->AltBody =
            "Your email verification code is {$code}. "
            . 'This code expires in 5 minutes.';

        $mail->send();

        /*
         * Store only the hash of the OTP in the PHP session.
         * The raw code is never stored.
         */
        $_SESSION['guest_otp'] = [
            'email' => $email,
            'hash' => password_hash(
                $code,
                PASSWORD_DEFAULT
            ),
            'expires_at' => time() + 300,
            'attempts' => 0,
        ];

        otpJson(200, [
            'success' => true,
            'message' => 'Verification code sent successfully.',
        ]);

    } catch (Throwable $e) {
        /*
         * Do not expose SMTP credentials or internal server details.
         */
        unset(
            $_SESSION['guest_otp'],
            $_SESSION['guest_email_verified']
        );

        error_log(
            'Guest OTP email delivery failed: ' . $e->getMessage()
        );

        otpJson(500, [
            'success' => false,
            'message' => 'Unable to send the verification code. Please try again later.',
        ]);
    }
}

/**
 * Verify the OTP submitted by the guest.
 *
 * Request:
 * {
 *     "action": "verify",
 *     "email": "guest@example.com",
 *     "code": "123456"
 * }
 */
function verifyGuestOtp(string $email, string $code): void
{
    $email = strtolower(trim($email));
    $code = trim($code);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        otpJson(422, [
            'success' => false,
            'message' => 'Invalid email address.',
        ]);
    }

    $otp = $_SESSION['guest_otp'] ?? null;

    if (
        !is_array($otp) ||
        !isset(
            $otp['email'],
            $otp['hash'],
            $otp['expires_at'],
            $otp['attempts']
        ) ||
        !is_string($otp['email']) ||
        !is_string($otp['hash'])
    ) {
        otpJson(400, [
            'success' => false,
            'message' => 'No active verification code was found. Request a new code.',
        ]);
    }

    /*
     * The submitted email must match the email to which
     * the OTP was sent.
     */
    if (!hash_equals($otp['email'], $email)) {
        otpJson(400, [
            'success' => false,
            'message' => 'The email address does not match the verification request.',
        ]);
    }

    /*
     * Check expiration.
     */
    if (time() >= (int) $otp['expires_at']) {
        unset($_SESSION['guest_otp']);

        otpJson(400, [
            'success' => false,
            'message' => 'The verification code has expired. Request a new code.',
        ]);
    }

    /*
     * Allow a maximum of five incorrect attempts.
     */
    if ((int) $otp['attempts'] >= 5) {
        unset($_SESSION['guest_otp']);

        otpJson(429, [
            'success' => false,
            'message' => 'Too many incorrect attempts. Request a new code.',
        ]);
    }

    /*
     * Validate the six-digit code and compare its hash.
     */
    if (
        !preg_match('/^\d{6}$/', $code) ||
        !password_verify($code, $otp['hash'])
    ) {
        $_SESSION['guest_otp']['attempts']++;

        if ((int) $_SESSION['guest_otp']['attempts'] >= 5) {
            unset($_SESSION['guest_otp']);

            otpJson(429, [
                'success' => false,
                'message' => 'Too many incorrect attempts. Request a new code.',
            ]);
        }

        otpJson(400, [
            'success' => false,
            'message' => 'Incorrect verification code.',
        ]);
    }

    /*
     * Mark this exact email as verified in the current PHP session.
     * The booking endpoint must check this session value.
     */
    $_SESSION['guest_email_verified'] = $email;

    // A successfully verified OTP cannot be reused.
    unset($_SESSION['guest_otp']);

    otpJson(200, [
        'success' => true,
        'message' => 'Email address verified successfully.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Request routing
|--------------------------------------------------------------------------
*/

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    otpJson(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

$body = otpInput();

$action = $body['action'] ?? null;

if (!is_string($action)) {
    otpJson(400, [
        'success' => false,
        'message' => 'A valid action is required.',
    ]);
}

if ($action === 'send') {
    $email = $body['email'] ?? null;

    if (!is_string($email)) {
        otpJson(422, [
            'success' => false,
            'message' => 'A valid email address is required.',
        ]);
    }

    sendGuestOtp($email);
}

if ($action === 'verify') {
    $email = $body['email'] ?? null;
    $code = $body['code'] ?? null;

    if (!is_string($email) || !is_string($code)) {
        otpJson(422, [
            'success' => false,
            'message' => 'Both email and verification code are required.',
        ]);
    }

    verifyGuestOtp($email, $code);
}

otpJson(400, [
    'success' => false,
    'message' => 'Unsupported action. Use "send" or "verify".',
]);