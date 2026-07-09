<?php

class Validator
{
    public static function require(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                Response::error("Missing required field: {$field}", 422);
            }
        }
    }

    public static function email(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }
    }

    public static function int(mixed $value, string $label = 'id'): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < 1) {
            Response::error("Invalid {$label}", 422);
        }
        return (int)$int;
    }
}
