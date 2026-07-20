<?php
// #21210 / #21314 — password_hash + password_verify soft-null on 8.4
// (php-src ext/standard/password.c Z_PARAM_STR; reverts #20174 TypeError)
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
try {
    $h = password_hash(null, PASSWORD_DEFAULT);
    echo 'password_hash=', (is_string($h) && str_starts_with($h, '$2y$') ? 'OK' : 'BAD'), "\n";
} catch (TypeError $e) {
    echo 'password_hash ', $e->getMessage(), "\n";
}
try {
    echo 'password_verify=', var_export(password_verify(null, 'x'), true), "\n";
} catch (TypeError $e) {
    echo 'password_verify ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 2), "\n";
