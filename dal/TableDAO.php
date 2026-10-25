<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../bal/dto/TableDTO.php';

class TableDAO
{
    public static function getAllTables(): array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `TableID`,
                `TableNumber`,
                `Booking_price`,
                `deposit`,
                `MaxCapacity`,
                `Status`
            FROM `Tables`
            ORDER BY `TableNumber` ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $tables = [];

        foreach ($rows as $row) {
            $tables[] = self::mapToDTO($row);
        }

        return $tables;
    }

    public static function getTableById(int $tableId): ?TableDTO
    {
        if ($tableId <= 0) {
            return null;
        }

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `TableID`,
                `TableNumber`,
                `Booking_price`,
                `deposit`,
                `MaxCapacity`,
                `Status`
            FROM `Tables`
            WHERE `TableID` = :tableId
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tableId', $tableId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : self::mapToDTO($row);
    }

    private static function mapToDTO(array $row): TableDTO
    {
        return new TableDTO(
            (int) $row['TableID'],
            (int) $row['TableNumber'],
            (string) $row['Booking_price'],
            (string) $row['deposit'],
            (int) $row['MaxCapacity'],
            (string) $row['Status']
        );
    }
}