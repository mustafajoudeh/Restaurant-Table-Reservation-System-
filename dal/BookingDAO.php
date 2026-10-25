<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../bal/dto/BookingDTO.php';
require_once __DIR__ . '/../bal/dto/BookingCreateDTO.php';
require_once __DIR__ . '/../bal/dto/BookingUpdateDTO.php';

class BookingDAO
{
    public static function getAllBookings(): array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                BookingID,
                TableID,
                UserID,
                Status,
                NumberOfIndividuals,
                NumberOfChildren,
                StartDateTime,
                EndDateTime
            FROM bookings
            ORDER BY StartDateTime DESC
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bookings = [];

        foreach ($rows as $row) {
            $bookings[] = self::mapToDTO($row);
        }

        return $bookings;
    }

    public static function getBookingById(
        int $bookingId
    ): ?BookingDTO {
        if ($bookingId <= 0) {
            return null;
        }

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                BookingID,
                TableID,
                UserID,
                Status,
                NumberOfIndividuals,
                NumberOfChildren,
                StartDateTime,
                EndDateTime
            FROM bookings
            WHERE BookingID = :bookingId
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(
            ':bookingId',
            $bookingId,
            PDO::PARAM_INT
        );
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return self::mapToDTO($row);
    }

    public static function createBooking(
        BookingCreateDTO $booking,
        ?int $userId
    ): int {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'CALL SP_CreateBooking(?, ?, ?, ?, ?, ?)'
        );

        $stmt->bindValue(
            1,
            $booking->tableId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            2,
            $userId,
            $userId === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_INT
        );

        $stmt->bindValue(
            3,
            $booking->numberOfIndividuals,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            4,
            $booking->numberOfChildren,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            5,
            $booking->startDateTime,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            6,
            $booking->endDateTime,
            PDO::PARAM_STR
        );

        $stmt->execute();

        // SP_CreateBooking returns BookingID using SELECT.
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Clear the stored procedure result set before another query.
        $stmt->closeCursor();

        if (
            $result === false ||
            !isset($result['BookingID'])
        ) {
            throw new RuntimeException(
                'The booking procedure did not return a BookingID.'
            );
        }

        return (int) $result['BookingID'];
    }

    public static function saveGuestDetails(
    int $bookingId,
    string $guestName,
    string $guestEmail,
    ?string $guestPhone
): void {
    if ($bookingId <= 0) {
        throw new InvalidArgumentException(
            'Booking ID must be a positive integer.'
        );
    }

    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'UPDATE bookings
         SET GuestName = :guestName,
             GuestEmail = :guestEmail,
             GuestPhone = :guestPhone
         WHERE BookingID = :bookingId'
    );

    $stmt->execute([
        'guestName' => $guestName,
        'guestEmail' => $guestEmail,
        'guestPhone' => $guestPhone,
        'bookingId' => $bookingId,
    ]);
}

    public static function updateBooking(
        int $bookingId,
        BookingUpdateDTO $booking
    ): bool {
        if ($bookingId <= 0) {
            return false;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'CALL SP_UpdateBooking(?, ?, ?, ?, ?, ?)'
        );

        $stmt->bindValue(
            1,
            $bookingId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            2,
            $booking->tableId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            3,
            $booking->numberOfIndividuals,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            4,
            $booking->numberOfChildren,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            5,
            $booking->startDateTime,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            6,
            $booking->endDateTime,
            PDO::PARAM_STR
        );

        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $result !== false
            && isset($result['BookingID'])
            && (int) $result['BookingID'] === $bookingId;
    }

    public static function cancelBooking(int $bookingId): array
{
    $pdo = Database::getConnection();

    try {
        $pdo->beginTransaction();

        // Lock the booking to prevent concurrent payment/cancellation changes.
        $stmt = $pdo->prepare(
            "SELECT BookingID, Status, StartDateTime
             FROM bookings
             WHERE BookingID = ?
             FOR UPDATE"
        );
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();

            return [
                'success' => false,
                'code' => 'NOT_FOUND'
            ];
        }

        if (in_array(
            $booking['Status'],
            ['Cancelled', 'Completed', 'No-Show'],
            true
        )) {
            $pdo->rollBack();

            return [
                'success' => false,
                'code' => 'INVALID_STATUS'
            ];
        }

        // A refund is eligible only when cancellation is at least 24 hours
        // before the reservation, and an original payment is Completed.
        $eligibleForRefund = strtotime($booking['StartDateTime'])
            >= time() + (24 * 60 * 60);

        // Lock existing payments for this booking.
        $stmt = $pdo->prepare(
            "SELECT PaymentID, TransactionType, Amount,
                    PaymentMethod, PaymentStatus
             FROM payments
             WHERE BookingID = ?
             FOR UPDATE"
        );
        $stmt->execute([$bookingId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cancel the booking.
        $stmt = $pdo->prepare(
            "UPDATE bookings
             SET Status = 'Cancelled'
             WHERE BookingID = ?"
        );
        $stmt->execute([$bookingId]);

        // Pending payments must not be completed after cancellation.
        $stmt = $pdo->prepare(
            "UPDATE payments
             SET PaymentStatus = 'Failed'
             WHERE BookingID = ?
               AND PaymentStatus = 'Pending'
               AND TransactionType = 'Deposit'"
        );
        $stmt->execute([$bookingId]);

        $refundCreated = false;

        if ($eligibleForRefund) {
            foreach ($payments as $payment) {
                if (
                    $payment['TransactionType'] !== 'Deposit'
                    || $payment['PaymentStatus'] !== 'Completed'
                ) {
                    continue;
                }

                $reference = 'REF-' . bin2hex(random_bytes(16));

                $stmt = $pdo->prepare(
                    "INSERT INTO payments
                        (BookingID, TransactionType, Amount,
                         PaymentMethod, TransactionReference, PaymentStatus)
                     VALUES (?, 'Refund', ?, ?, ?, 'Pending')"
                );

                $stmt->execute([
                    $bookingId,
                    $payment['Amount'],
                    $payment['PaymentMethod'],
                    $reference
                ]);

                $refundCreated = true;
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'refundCreated' => $refundCreated
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
    private static function mapToDTO(
        array $row
    ): BookingDTO {
        return new BookingDTO(
            (int) $row['BookingID'],
            (int) $row['TableID'],
            $row['UserID'] === null
                ? null
                : (int) $row['UserID'],
            (string) $row['Status'],
            (int) $row['NumberOfIndividuals'],
            (int) $row['NumberOfChildren'],
            (string) $row['StartDateTime'],
            (string) $row['EndDateTime']
        );
    }
}