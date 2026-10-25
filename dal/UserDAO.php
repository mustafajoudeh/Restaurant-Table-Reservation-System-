<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../bal/dto/UserDTO.php';
require_once __DIR__ . '/../bal/dto/RegisterUserDTO.php';

class UserDAO
{
    public static function createUser(
        RegisterUserDTO $user,
        string $role = 'Customer'
    ): int {
        $pdo = Database::getConnection();

        $sql = "
            INSERT INTO `users`
                (`Role`, `Username`, `Email`, `PhoneNumber`, `Password`)
            VALUES
                (:role, :username, :email, :phoneNumber, :password)
        ";

        $stmt = $pdo->prepare($sql);

        $hashedPassword = password_hash(
            $user->password,
            PASSWORD_DEFAULT
        );

        if ($hashedPassword === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $stmt->execute([
            'role'        => $role,
            'username'    => $user->username,
            'email'       => $user->email,
            'phoneNumber' => $user->phoneNumber,
            'password'    => $hashedPassword,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function getUserById(int $userId): ?array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `UserID`,
                `Role`,
                `Username`,
                `Email`,
                `PhoneNumber`
            FROM `users`
            WHERE `UserID` = :userId
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['userId' => $userId]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public static function getAllUsers(): array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `UserID`,
                `Role`,
                `Username`,
                `Email`,
                `PhoneNumber`
            FROM `users`
            ORDER BY `UserID` DESC
        ";

        return $pdo->query($sql)->fetchAll();
    }

    public static function getUserByEmail(string $email): ?array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `UserID`,
                `Role`,
                `Username`,
                `Email`,
                `PhoneNumber`
            FROM `users`
            WHERE `Email` = :email
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    /**
     * Returns safe user data only when the password is correct.
     */
    public static function authenticateUser(
        string $email,
        string $password
    ): ?array {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                `UserID`,
                `Role`,
                `Username`,
                `Email`,
                `PhoneNumber`,
                `Password`
            FROM `users`
            WHERE `Email` = :email
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);

        $user = $stmt->fetch();

        if (
            $user === false ||
            !password_verify($password, $user['Password'])
        ) {
            return null;
        }

        unset($user['Password']);

        return $user;
    }

    public static function updateUser(UserDTO $user): bool
    {
        if ($user->id === null) {
            throw new InvalidArgumentException(
                'User ID is required.'
            );
        }

        $pdo = Database::getConnection();

        $sql = "
            UPDATE `users`
            SET
                `Username` = :username,
                `Email` = :email,
                `PhoneNumber` = :phoneNumber
            WHERE `UserID` = :userId
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            'username'    => $user->username,
            'email'       => $user->email,
            'phoneNumber' => $user->phoneNumber,
            'userId'      => $user->id,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        // A zero row count can mean either no change or no such user.
        return self::profileMatches($user);
    }

    private static function profileMatches(UserDTO $user): bool
    {
        if ($user->id === null) {
            return false;
        }

        $current = self::getUserById($user->id);

        if ($current === null) {
            return false;
        }

        return $current['Username'] === $user->username
            && $current['Email'] === $user->email
            && $current['PhoneNumber'] === $user->phoneNumber;
    }

    public static function deleteUser(int $userId): bool
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'DELETE FROM `users` WHERE `UserID` = :userId'
        );

        $stmt->execute(['userId' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public static function emailExists(
        string $email,
        ?int $excludeUserId = null
    ): bool {
        $sql = 'SELECT 1 FROM `users` WHERE `Email` = :email';
        $params = ['email' => $email];

        if ($excludeUserId !== null) {
            $sql .= ' AND `UserID` != :userId';
            $params['userId'] = $excludeUserId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public static function usernameExists(
        string $username,
        ?int $excludeUserId = null
    ): bool {
        $sql = 'SELECT 1 FROM `users` WHERE `Username` = :username';
        $params = ['username' => $username];

        if ($excludeUserId !== null) {
            $sql .= ' AND `UserID` != :userId';
            $params['userId'] = $excludeUserId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Changes a password only after verifying the current password.
     */
    public static function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword
    ): bool {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT `Password`
            FROM `users`
            WHERE `UserID` = :userId
            LIMIT 1
        ");

        $stmt->execute(['userId' => $userId]);

        $storedHash = $stmt->fetchColumn();

        if (
            $storedHash === false ||
            !password_verify($currentPassword, $storedHash)
        ) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($newHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $update = $pdo->prepare("
            UPDATE `users`
            SET `Password` = :password
            WHERE `UserID` = :userId
        ");

        $update->execute([
            'password' => $newHash,
            'userId'   => $userId,
        ]);

        return $update->rowCount() > 0;
    }
}