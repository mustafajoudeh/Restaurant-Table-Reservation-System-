<?php

declare(strict_types=1);

require_once __DIR__ . '/../dal/PaymentDAO.php';
require_once __DIR__ . '/../bal/dto/PaymentDTO.php';
require_once __DIR__ . '/../bal/dto/PaymentCreateDTO.php';
require_once __DIR__ . '/BookingBAL.php';

class PaymentBAL
{
    public ?int $paymentId;
    public int $bookingId;
    public string $transactionType;
    public string $amount;
    public string $paymentMethod;
    public string $transactionReference;
    public string $paymentStatus;
    public string $createdAt;

    public function __construct(
        ?int $paymentId = null,
        int $bookingId = 0,
        string $transactionType = '',
        string $amount = '0.00',
        string $paymentMethod = '',
        string $transactionReference = '',
        string $paymentStatus = 'Pending',
        string $createdAt = ''
    ) {
        $this->paymentId = $paymentId;
        $this->bookingId = $bookingId;
        $this->transactionType = $transactionType;
        $this->amount = $amount;
        $this->paymentMethod = $paymentMethod;
        $this->transactionReference = $transactionReference;
        $this->paymentStatus = $paymentStatus;
        $this->createdAt = $createdAt;
    }

    /**
     * Return all payments.
     */
    public static function getAllPayments(): array
    {
        $dtos = PaymentDAO::getAllPayments();
        $payments = [];

        foreach ($dtos as $dto) {
            $payments[] = self::fromDTO($dto);
        }

        return $payments;
    }

    /**
     * Find a payment by its ID.
     */
    public static function findById(int $paymentId): ?self
    {
        if ($paymentId <= 0) {
            return null;
        }

        $dto = PaymentDAO::getPaymentById($paymentId);

        return $dto === null
            ? null
            : self::fromDTO($dto);
    }

    /**
     * Return all payments associated with a booking.
     */
    public static function getPaymentsByBookingId(
        int $bookingId
    ): array {
        if ($bookingId <= 0) {
            throw new InvalidArgumentException(
                'Booking ID must be a positive integer.'
            );
        }

        // Ensure the booking exists.
        if (BookingBAL::findById($bookingId) === null) {
            return [];
        }

        $dtos = PaymentDAO::getPaymentsByBookingId($bookingId);
        $payments = [];

        foreach ($dtos as $dto) {
            $payments[] = self::fromDTO($dto);
        }

        return $payments;
    }

    /**
     * Validate and create a payment record.
     */
    public static function createPayment(
        PaymentCreateDTO $payment
    ): int {
        self::validatePaymentData($payment);

        // A payment cannot refer to a nonexistent booking.
        $booking = BookingBAL::findById($payment->bookingId);

        if ($booking === null) {
            throw new InvalidArgumentException(
                'The specified booking does not exist.'
            );
        }

        return PaymentDAO::createPayment($payment);
    }

    private static function validatePaymentData(
        PaymentCreateDTO $payment
    ): void {
        if ($payment->bookingId <= 0) {
            throw new InvalidArgumentException(
                'Booking ID must be a positive integer.'
            );
        }

        // DECIMAL(5,2) supports at most 999.99.
        if (
            !is_numeric($payment->amount) ||
            !is_finite((float) $payment->amount) ||
            (float) $payment->amount <= 0 ||
            (float) $payment->amount > 999.99 ||
            !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $payment->amount)
        ) {
            throw new InvalidArgumentException(
                'Amount must be greater than zero and no more than 999.99, with up to two decimal places.'
            );
        }

        if (
            trim($payment->transactionType) === '' ||
            strlen($payment->transactionType) > 30
        ) {
            throw new InvalidArgumentException(
                'Transaction type is required and must not exceed 30 characters.'
            );
        }

        if (
            trim($payment->paymentMethod) === '' ||
            strlen($payment->paymentMethod) > 30
        ) {
            throw new InvalidArgumentException(
                'Payment method is required and must not exceed 30 characters.'
            );
        }

        if (
            trim($payment->transactionReference) === '' ||
            strlen($payment->transactionReference) > 150
        ) {
            throw new InvalidArgumentException(
                'Transaction reference is required and must not exceed 150 characters.'
            );
        }

        if (
            trim($payment->paymentStatus) === '' ||
            strlen($payment->paymentStatus) > 30
        ) {
            throw new InvalidArgumentException(
                'Payment status is required and must not exceed 30 characters.'
            );
        }
    }


    
public static function confirmCashPayment(int $paymentId): bool
{
    if ($paymentId <= 0) {
        throw new InvalidArgumentException(
            'Payment ID must be a positive integer.'
        );
    }

    $payment = PaymentDAO::getPaymentById($paymentId);

    if ($payment === null) {
        return false;
    }

    if ($payment->paymentMethod !== 'Cash') {
        throw new InvalidArgumentException(
            'Only cash payments can be confirmed through this endpoint.'
        );
    }

    if ($payment->paymentStatus !== 'Pending') {
        throw new InvalidArgumentException(
            'Only pending payments can be confirmed.'
        );
    }

    return PaymentDAO::confirmCashPayment($paymentId);
}


public static function simulateOnlinePayment(
    int $paymentId,
    string $result
): bool {
    if ($paymentId <= 0) {
        throw new InvalidArgumentException(
            'Payment ID must be a positive integer.'
        );
    }

    if (!in_array($result, ['Completed', 'Failed'], true)) {
        throw new InvalidArgumentException(
            'Result must be Completed or Failed.'
        );
    }

    $payment = PaymentDAO::getPaymentById($paymentId);

    if ($payment === null) {
        return false;
    }

    if ($payment->paymentMethod !== 'Online') {
        throw new InvalidArgumentException(
            'Only Online payments can use the simulation endpoint.'
        );
    }

    if ($payment->paymentStatus !== 'Pending') {
        throw new InvalidArgumentException(
            'Only pending payments can be simulated.'
        );
    }

    return PaymentDAO::simulateOnlinePayment(
        $paymentId,
        $result
    );
}

public static function confirmRefund(int $paymentId): bool
{
    if ($paymentId <= 0) {
        return false;
    }

    $payment = self::findById($paymentId);

    if (!$payment) {
        return false;
    }

    if (
        $payment->transactionType !== 'Refund'
        || $payment->paymentStatus !== 'Pending'
    ) {
        return false;
    }

    return PaymentDAO::confirmRefund($paymentId);
}

    private static function fromDTO(PaymentDTO $dto): self
    {
        return new self(
            $dto->paymentId,
            $dto->bookingId,
            $dto->transactionType,
            $dto->amount,
            $dto->paymentMethod,
            $dto->transactionReference,
            $dto->paymentStatus,
            $dto->createdAt
        );
    }
}