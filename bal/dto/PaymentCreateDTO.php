<?php

declare(strict_types=1);

class PaymentCreateDTO
{
    public int $bookingId;
    public string $transactionType;
    public string $amount;
    public string $paymentMethod;
    public string $transactionReference;
    public string $paymentStatus;

    public function __construct(
        int $bookingId,
        string $transactionType,
        string $amount,
        string $paymentMethod,
        string $transactionReference,
        string $paymentStatus
    ) {
        $this->bookingId = $bookingId;
        $this->transactionType = $transactionType;
        $this->amount = $amount;
        $this->paymentMethod = $paymentMethod;
        $this->transactionReference = $transactionReference;
        $this->paymentStatus = $paymentStatus;
    }
}