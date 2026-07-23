<?php
// Repro #22492 — PHP 8.4 exit()/die() reject array|object status with TypeError.
ini_set('display_errors', '1');
try {
    $e = 'exit';
    $e([1, 2]);
    echo "SURVIVED\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
try {
    die(new stdClass());
    echo "SURVIVED2\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
