<?php
declare(strict_types=1);

class UserDTO
{
    public ?int $id = null;
    public string $role = '';
    public string $username = '';
    public string $email = '';
    public ?string $phoneNumber = null;
}