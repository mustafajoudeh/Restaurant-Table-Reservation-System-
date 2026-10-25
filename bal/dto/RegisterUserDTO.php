<?php
declare(strict_types=1);

class RegisterUserDTO
{
    public string $username;
    public string $email;
    public string $password;
    public ?string $phoneNumber;

    public function __construct(
        string $username,
        string $email,
        string $password,
        ?string $phoneNumber = null
    ) {
        $this->username = $username;
        $this->email = $email;
        $this->password = $password;
        $this->phoneNumber = $phoneNumber;
    }
}