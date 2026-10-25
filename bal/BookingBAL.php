<?php

declare(strict_types=1);

require_once __DIR__ . '/../dal/BookingDAO.php';
require_once __DIR__ . '/dto/BookingDTO.php';
require_once __DIR__ . '/dto/BookingCreateDTO.php';
require_once __DIR__ . '/dto/BookingUpdateDTO.php';

class BookingBAL
{
    public ?int $bookingId;
    public int $tableId;
    public ?int $userId;
    public string $status;
    public int $numberOfIndividuals;
    public int $numberOfChildren;
    public string $startDateTime;
    public string $endDateTime;

    public function __construct(
        ?int $bookingId = null,
        int $tableId = 0,
        ?int $userId = null,
        string $status = 'Pending',
        int $numberOfIndividuals = 1,
        int $numberOfChildren = 0,
        string $startDateTime = '',
        string $endDateTime = ''
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

    public static function getAllBookings(): array
    {
        $dtos = BookingDAO::getAllBookings();
        $bookings = [];

        foreach ($dtos as $dto) {
            $bookings[] = self::fromDTO($dto);
        }

        return $bookings;
    }

    public static function findById(int $bookingId): ?self
    {
        if ($bookingId <= 0) {
            return null;
        }

        $dto = BookingDAO::getBookingById($bookingId);

        return $dto === null
            ? null
            : self::fromDTO($dto);
    }

    public static function createBooking(
        BookingCreateDTO $booking,
        ?int $userId
    ): int {
        self::validateBookingData(
            $booking->tableId,
            $booking->startDateTime,
            $booking->endDateTime,
            $booking->numberOfIndividuals,
            $booking->numberOfChildren
        );

        if ($userId !== null && $userId <= 0) {
            throw new InvalidArgumentException(
                'User ID must be a positive integer.'
            );
        }

        return BookingDAO::createBooking($booking, $userId);
    }

    public static function updateBooking(
        int $bookingId,
        BookingUpdateDTO $booking
    ): bool {
        if ($bookingId <= 0) {
            throw new InvalidArgumentException(
                'Booking ID must be a positive integer.'
            );
        }

        self::validateBookingData(
            $booking->tableId,
            $booking->startDateTime,
            $booking->endDateTime,
            $booking->numberOfIndividuals,
            $booking->numberOfChildren
        );

        // Verify the booking exists before attempting the update.
        if (BookingDAO::getBookingById($bookingId) === null) {
            return false;
        }

        return BookingDAO::updateBooking($bookingId, $booking);
    }

    private static function validateBookingData(
        int $tableId,
        string $startDateTime,
        string $endDateTime,
        int $numberOfIndividuals,
        int $numberOfChildren
    ): void {
        if ($tableId <= 0) {
            throw new InvalidArgumentException(
                'Table ID must be a positive integer.'
            );
        }

        if ($numberOfIndividuals <= 0) {
            throw new InvalidArgumentException(
                'Number of individuals must be greater than zero.'
            );
        }

        if ($numberOfChildren < 0) {
            throw new InvalidArgumentException(
                'Number of children cannot be negative.'
            );
        }

        $start = self::parseDateTime($startDateTime);
        $end = self::parseDateTime($endDateTime);

        if ($start === null || $end === null) {
            throw new InvalidArgumentException(
                'Start and end must use YYYY-MM-DD HH:MM:SS format.'
            );
        }

        if ($end <= $start) {
            throw new InvalidArgumentException(
                'End date and time must be after start date and time.'
            );
        }
    }

    private static function parseDateTime(
        string $dateTime
    ): ?DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $dateTime
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false ||
            (
                $errors !== false &&
                (
                    $errors['warning_count'] > 0 ||
                    $errors['error_count'] > 0
                )
            ) ||
            $parsed->format('Y-m-d H:i:s') !== $dateTime
        ) {
            return null;
        }

        return $parsed;
    }

    public static function cancelBooking(int $bookingId): array
{
    if ($bookingId <= 0) {
        return [
            'success' => false,
            'code' => 'INVALID_ID'
        ];
    }

    $booking = self::findById($bookingId);

    if ($booking === null || $booking === false) {
        return [
            'success' => false,
            'code' => 'NOT_FOUND'
        ];
    }

    return BookingDAO::cancelBooking($bookingId);
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

    $guestName = trim($guestName);
    $guestEmail = strtolower(trim($guestEmail));
    $guestPhone = trim((string) $guestPhone);

    if ($guestName === '' || mb_strlen($guestName) > 150) {
        throw new InvalidArgumentException(
            'A valid guest name is required.'
        );
    }

    if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'A valid guest email is required.'
        );
    }

    if (mb_strlen($guestPhone) > 30) {
        throw new InvalidArgumentException(
            'Guest phone number is too long.'
        );
    }

    BookingDAO::saveGuestDetails(
        $bookingId,
        $guestName,
        $guestEmail,
        $guestPhone === '' ? null : $guestPhone
    );
}
    private static function fromDTO(BookingDTO $dto): self
    {
        return new self(
            $dto->bookingId,
            $dto->tableId,
            $dto->userId,
            $dto->status,
            $dto->numberOfIndividuals,
            $dto->numberOfChildren,
            $dto->startDateTime,
            $dto->endDateTime
        );
    }
}