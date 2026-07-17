<?php
// #3389 — crypt() TypeError arg name must match Zend ($string, not $password).
// Null TypeError requires PHP_COMPILER_PROFILE=8.4 (Zend 8.2 still deprecates+coerces).
foreach ([
    'null' => null,
    'array' => [],
] as $label => $arg) {
    try {
        crypt($arg, 'xx');
        echo "$label: NO_ERROR\n";
    } catch (Throwable $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}

$salt = '$2y$10$' . str_repeat('a', 22);
$hash = crypt('secret', $salt);
echo str_starts_with($hash, '$2y$10$') && strlen($hash) === 60 ? "bcrypt_ok\n" : "bcrypt_fail\n";
