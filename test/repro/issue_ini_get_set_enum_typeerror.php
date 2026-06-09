<?php
// Issue #5915: ini_get()/ini_set() enum case option operands must TypeError (php-src-strict).
enum Es: string { case B = 'display_errors'; }
try {
    ini_get(Es::B);
} catch (Throwable $e) {
    echo 'ini_get:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    ini_set(Es::B, '1');
} catch (Throwable $e) {
    echo 'ini_set:', get_class($e), ': ', $e->getMessage(), "\n";
}
