<?php
declare(strict_types=1);

/** Issue #7172 — defined() must TypeError on enum case operands (php-src Z_PARAM_STR). */
enum E: string { case A = 'x'; }

try {
    defined(E::A);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
