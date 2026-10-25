<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../bal/dto/PaymentDTO.php';
require_once __DIR__ . '/../bal/dto/PaymentCreateDTO.php';

class PaymentDAO
{
    public static function getAllPayments(): array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                PaymentID,
                BookingID,
                TransactionType,
                Amount,
                PaymentMethod,
                TransactionReference,
                PaymentStatus,
                CreatedAt
            FROM payments
            ORDER BY CreatedAt DESC, PaymentID DESC
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payments = [];

        foreach ($rows as $row) {
            $payments[] = self::mapToDTO($row);
        }

        return $payments;
    }

    public static function getPaymentById(
        int $paymentId
    ): ?PaymentDTO {
        if ($paymentId <= 0) {
            return null;
        }

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                PaymentID,
                BookingID,
                TransactionType,
                Amount,
                PaymentMethod,
                TransactionReference,
                PaymentStatus,
                CreatedAt
            FROM payments
            WHERE PaymentID = :paymentId
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(
            ':paymentId',
            $paymentId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return self::mapToDTO($row);
    }

    public static function getPaymentsByBookingId(
        int $bookingId
    ): array {
        if ($bookingId <= 0) {
            return [];
        }

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                PaymentID,
                BookingID,
                TransactionType,
                Amount,
                PaymentMethod,
                TransactionReference,
                PaymentStatus,
                CreatedAt
            FROM payments
            WHERE BookingID = :bookingId
            ORDER BY CreatedAt DESC, PaymentID DESC
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(
            ':bookingId',
            $bookingId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payments = [];

        foreach ($rows as $row) {
            $payments[] = self::mapToDTO($row);
        }

        return $payments;
    }

  public static function createPayment(PaymentCreateDTO $payment): int
{
    $pdo = Database::getConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT BookingID, Status
             FROM bookings
             WHERE BookingID = ?
             FOR UPDATE"
        );
        $stmt->execute([$payment->bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking || $booking['Status'] !== 'Pending') {
            throw new DomainException(
                'Only pending bookings can receive a new deposit payment.'
            );
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM payments
             WHERE BookingID = ?
               AND PaymentStatus IN ('Pending', 'Completed')"
        );
        $stmt->execute([$payment->bookingId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException(
                'A pending or completed payment already exists for this booking.'
            );
        }

        $stmt = $pdo->prepare(
            "INSERT INTO payments
                (BookingID, TransactionType, Amount, PaymentMethod,
                 TransactionReference, PaymentStatus)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $payment->bookingId,
            $payment->transactionType,
            $payment->amount,
            $payment->paymentMethod,
            $payment->transactionReference,
            $payment->paymentStatus
        ]);

        $paymentId = (int) $pdo->lastInsertId();

        $pdo->commit();

        return $paymentId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

    
public static function confirmCashPayment(int $paymentId): bool
{
    $pdo = Database::getConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT p.BookingID, p.PaymentStatus, b.Status AS BookingStatus
             FROM payments p
             INNER JOIN bookings b ON b.BookingID = p.BookingID
             WHERE p.PaymentID = ?
               AND p.TransactionType = 'Deposit'
               AND p.PaymentMethod = 'Cash'
             FOR UPDATE"
        );
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$row
            || $row['PaymentStatus'] !== 'Pending'
            || $row['BookingStatus'] !== 'Pending'
        ) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE payments
             SET PaymentStatus = 'Completed'
             WHERE PaymentID = ?
               AND PaymentStatus = 'Pending'"
        );
        $stmt->execute([$paymentId]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE bookings
             SET Status = 'Confirmed'
             WHERE BookingID = ?
               AND Status = 'Pending'"
        );
        $stmt->execute([$row['BookingID']]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


public static function simulateOnlinePayment(
    int $paymentId,
    string $status
): bool {
    if (!in_array($status, ['Completed', 'Failed'], true)) {
        return false;
    }

    $pdo = Database::getConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT p.BookingID, p.PaymentStatus, b.Status AS BookingStatus
             FROM payments p
             INNER JOIN bookings b ON b.BookingID = p.BookingID
             WHERE p.PaymentID = ?
               AND p.TransactionType = 'Deposit'
               AND p.PaymentMethod = 'Online'
             FOR UPDATE"
        );
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$row
            || $row['PaymentStatus'] !== 'Pending'
            || $row['BookingStatus'] !== 'Pending'
        ) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE payments
             SET PaymentStatus = ?
             WHERE PaymentID = ?
               AND PaymentStatus = 'Pending'"
        );
        $stmt->execute([$status, $paymentId]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        if ($status === 'Completed') {
            $stmt = $pdo->prepare(
                "UPDATE bookings
                 SET Status = 'Confirmed'
                 WHERE BookingID = ?
                   AND Status = 'Pending'"
            );
            $stmt->execute([$row['BookingID']]);

            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
public static function confirmRefund(int $paymentId): bool
{
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        "UPDATE payments
         SET PaymentStatus = 'Completed'
         WHERE PaymentID = ?
           AND TransactionType = 'Refund'
           AND PaymentStatus = 'Pending'"
    );

    $stmt->execute([$paymentId]);

    return $stmt->rowCount() === 1;
}

    private static function mapToDTO(
        array $row
    ): PaymentDTO {
        return new PaymentDTO(
            (int) $row['PaymentID'],
            (int) $row['BookingID'],
            (string) $row['TransactionType'],
            (string) $row['Amount'],
            (string) $row['PaymentMethod'],
            (string) $row['TransactionReference'],
            (string) $row['PaymentStatus'],
            (string) $row['CreatedAt']
        );
    }
}