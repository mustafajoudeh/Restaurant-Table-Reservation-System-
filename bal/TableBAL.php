<?php
declare(strict_types=1);

require_once __DIR__ . '/../dal/TableDAO.php';
require_once __DIR__ . '/dto/TableDTO.php';

class TableBAL
{
    public ?int $id;
    public int $tableNumber;
    public string $bookingPrice;
    public string $deposit;
    public int $maxCapacity;
    public string $status;

    public function __construct(
        ?int $id = null,
        int $tableNumber = 0,
        string $bookingPrice = '0.00',
        string $deposit = '0.00',
        int $maxCapacity = 4,
        string $status = 'Available'
    ) {
        $this->id = $id;
        $this->tableNumber = $tableNumber;
        $this->bookingPrice = $bookingPrice;
        $this->deposit = $deposit;
        $this->maxCapacity = $maxCapacity;
        $this->status = $status;
    }

    public static function getAllTables(): array
    {
        $tableDTOs = TableDAO::getAllTables();
        $tables = [];

        foreach ($tableDTOs as $dto) {
            $tables[] = self::fromDTO($dto);
        }

        return $tables;
    }

    public static function findById(int $tableId): ?self
    {
        if ($tableId <= 0) {
            return null;
        }

        $dto = TableDAO::getTableById($tableId);

        return $dto === null ? null : self::fromDTO($dto);
    }

    private static function fromDTO(TableDTO $dto): self
    {
        return new self(
            $dto->id,
            $dto->tableNumber,
            $dto->bookingPrice,
            $dto->deposit,
            $dto->maxCapacity,
            $dto->status
        );
    }
}