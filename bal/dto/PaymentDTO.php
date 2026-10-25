<?php

declare(strict_types=1);

class PaymentDTO
{
    public int $paymentId;
    public int $bookingId;
    public string $transactionType;
    public string $amount;
    public string $paymentMethod;
    public string $transactionReference;
    public string $paymentStatus;
    public string $createdAt;

    public function __construct(
        int $paymentId,
        int $bookingId,
        string $transactionType,
        string $amount,
        string $paymentMethod,
        string $transactionReference,
        string $paymentStatus,
        string $createdAt
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
}