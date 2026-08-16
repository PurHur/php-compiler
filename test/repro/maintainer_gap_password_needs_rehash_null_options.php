<?php
// #31421 — password_needs_rehash(..., null) $options → TypeError (php-src Z_PARAM_ARRAY)
error_reporting(E_ALL);
try {
    password_needs_rehash('x', PASSWORD_DEFAULT, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $ok = password_needs_rehash('$2y$10$'.str_repeat('a', 22).str_repeat('b', 31), PASSWORD_DEFAULT, []);
    echo is_bool($ok) ? "ok\n" : "bad\n";
} catch (Throwable $e) {
    echo 'array_path: ', get_class($e), "\n";
}
