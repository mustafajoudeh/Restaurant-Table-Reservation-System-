<?php
declare(strict_types=1);

class BookingCreateDTO
{
    public int $tableId;
    public string $startDateTime;
    public string $endDateTime;
    public int $numberOfIndividuals;
    public int $numberOfChildren;

    public function __construct(
        int $tableId,
        string $startDateTime,
        string $endDateTime,
        int $numberOfIndividuals,
        int $numberOfChildren
    ) {
        $this->tableId = $tableId;
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->numberOfIndividuals = $numberOfIndividuals;
        $this->numberOfChildren = $numberOfChildren;
    }
}