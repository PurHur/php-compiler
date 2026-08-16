<?php
// #31421 — password_hash(..., null) $options → TypeError (php-src Z_PARAM_ARRAY)
error_reporting(E_ALL);
try {
    password_hash('x', PASSWORD_BCRYPT, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $h = password_hash('x', PASSWORD_BCRYPT, []);
    echo is_string($h) && strlen($h) > 20 ? "ok\n" : "bad\n";
} catch (Throwable $e) {
    echo 'array_path: ', get_class($e), "\n";
}
