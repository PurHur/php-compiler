<?php
declare(strict_types=1);

/** Issue #7017 — ini_get()/ini_set() must TypeError on enum case operands (php-src Z_PARAM_STR). */
enum E: string { case A = 'x'; }

try {
    ini_set(E::A, '1');
    echo "ini_set: no throw\n";
} catch (Throwable $e) {
    echo "ini_set: ", get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    ini_get(E::A);
    echo "ini_get: no throw\n";
} catch (Throwable $e) {
    echo "ini_get: ", get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    ini_set('display_errors', E::A);
    echo "ini_set value: no throw\n";
} catch (Throwable $e) {
    echo "ini_set value: ", get_class($e), ': ', $e->getMessage(), "\n";
}
