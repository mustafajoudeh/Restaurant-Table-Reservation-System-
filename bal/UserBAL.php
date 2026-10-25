<?php
declare(strict_types=1);

require_once __DIR__ . '/../dal/UserDAO.php';
require_once __DIR__ . '/dto/UserDTO.php';
require_once __DIR__ . '/dto/RegisterUserDTO.php';

class UserConflictException extends RuntimeException
{
}

class UserBAL
{
    private const MODE_ADD_NEW = 0;
    private const MODE_UPDATE = 1;

    private int $mode;
    private string $password;

    public ?int $id;
    public string $role;
    public string $username;
    public string $email;
    public ?string $phoneNumber;

    public function __construct(
        ?int $id = null,
        string $role = 'Customer',
        string $username = '',
        string $email = '',
        ?string $phoneNumber = null,
        string $password = ''
    ) {
        $this->id = $id;
        $this->role = $role;
        $this->username = $username;
        $this->email = $email;
        $this->phoneNumber = $phoneNumber;
        $this->password = $password;

        $this->mode = $id === null
            ? self::MODE_ADD_NEW
            : self::MODE_UPDATE;
    }

    private function addNewUser(): bool
    {
        $this->validateProfile();

        if (strlen($this->password) < 8) {
            throw new InvalidArgumentException(
                'Password must be at least 8 characters.'
            );
        }

        if (UserDAO::emailExists($this->email)) {
            throw new UserConflictException(
                'Email already exists.'
            );
        }

        if (UserDAO::usernameExists($this->username)) {
            throw new UserConflictException(
                'Username already exists.'
            );
        }

        $registration = new RegisterUserDTO(
            $this->username,
            $this->email,
            $this->password,
            $this->phoneNumber
        );

        // Never trust a role supplied by public registration.
        $this->role = 'Customer';

        $this->id = UserDAO::createUser(
            $registration,
            'Customer'
        );

        return $this->id > 0;
    }

    private function updateUser(): bool
    {
        if ($this->id === null) {
            throw new InvalidArgumentException(
                'User ID is required.'
            );
        }

        $this->validateProfile();

        if (UserDAO::emailExists($this->email, $this->id)) {
            throw new UserConflictException(
                'Email already exists.'
            );
        }

        if (UserDAO::usernameExists($this->username, $this->id)) {
            throw new UserConflictException(
                'Username already exists.'
            );
        }

        return UserDAO::updateUser($this->toDTO());
    }

    private function validateProfile(): void
    {
        $this->username = trim($this->username);
        $this->email = trim($this->email);

        if ($this->username === '') {
            throw new InvalidArgumentException(
                'Username is required.'
            );
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'A valid email is required.'
            );
        }
    }

    public static function findById(int $userId): ?self
    {
        if ($userId <= 0) {
            return null;
        }

        $data = UserDAO::getUserById($userId);

        return $data === null ? null : self::fromArray($data);
    }

    public static function findByEmail(string $email): ?self
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $data = UserDAO::getUserByEmail($email);

        return $data === null ? null : self::fromArray($data);
    }

    public static function authenticate(
        string $email,
        string $password
    ): ?self {
        $data = UserDAO::authenticateUser(
            trim($email),
            $password
        );

        return $data === null ? null : self::fromArray($data);
    }

    /**
     * @return self[]
     */
    public static function getAllUsers(): array
    {
        $rows = UserDAO::getAllUsers();

        return array_map(
            static fn(array $row): self => self::fromArray($row),
            $rows
        );
    }

    private static function fromArray(array $data): self
    {
        return new self(
            (int) $data['UserID'],
            (string) $data['Role'],
            (string) $data['Username'],
            (string) $data['Email'],
            isset($data['PhoneNumber'])
                ? (string) $data['PhoneNumber']
                : null
        );
    }

    public function save(): bool
    {
        if ($this->mode === self::MODE_ADD_NEW) {
            if (!$this->addNewUser()) {
                return false;
            }

            $this->mode = self::MODE_UPDATE;

            // Do not keep the plain password in this object after saving.
            $this->password = '';

            return true;
        }

        if ($this->mode === self::MODE_UPDATE) {
            return $this->updateUser();
        }

        return false;
    }

    public static function deleteUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return UserDAO::deleteUser($userId);
    }

    public function changePassword(
        string $currentPassword,
        string $newPassword
    ): bool {
        if ($this->id === null) {
            throw new InvalidArgumentException(
                'Cannot change password for an unsaved user.'
            );
        }

        if ($currentPassword === '') {
            throw new InvalidArgumentException(
                'Current password is required.'
            );
        }

        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException(
                'New password must be at least 8 characters.'
            );
        }

        if ($currentPassword === $newPassword) {
            throw new InvalidArgumentException(
                'New password must differ from the current password.'
            );
        }

        return UserDAO::changePassword(
            $this->id,
            $currentPassword,
            $newPassword
        );
    }

    private function toDTO(): UserDTO
    {
        $user = new UserDTO();

        $user->id = $this->id;
        $user->role = $this->role;
        $user->username = $this->username;
        $user->email = $this->email;
        $user->phoneNumber = $this->phoneNumber;

        return $user;
    }
}