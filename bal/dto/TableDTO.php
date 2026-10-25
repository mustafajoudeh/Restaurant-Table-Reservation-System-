<?php
declare(strict_types=1);

class TableDTO
{
    public int $id;
    public int $tableNumber;
    public string $bookingPrice;
    public string $deposit;
    public int $maxCapacity;
    public string $status;

    public function __construct(
        int $id,
        int $tableNumber,
        string $bookingPrice,
        string $deposit,
        int $maxCapacity,
        string $status
    ) {
        $this->id = $id;
        $this->tableNumber = $tableNumber;
        $this->bookingPrice = $bookingPrice;
        $this->deposit = $deposit;
        $this->maxCapacity = $maxCapacity;
        $this->status = $status;
    }
}