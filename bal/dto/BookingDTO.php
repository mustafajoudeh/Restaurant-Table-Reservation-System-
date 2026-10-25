<?php
declare(strict_types=1);

class BookingDTO
{
    public int $bookingId;
    public int $tableId;
    public ?int $userId;
    public string $status;
    public int $numberOfIndividuals;
    public int $numberOfChildren;
    public string $startDateTime;
    public string $endDateTime;

    public function __construct(
        int $bookingId,
        int $tableId,
        ?int $userId,
        string $status,
        int $numberOfIndividuals,
        int $numberOfChildren,
        string $startDateTime,
        string $endDateTime
    ) {
        $this->bookingId = $bookingId;
        $this->tableId = $tableId;
        $this->userId = $userId;
        $this->status = $status;
        $this->numberOfIndividuals = $numberOfIndividuals;
        $this->numberOfChildren = $numberOfChildren;
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
    }
}