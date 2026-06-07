<?php
declare(strict_types=1);
// Maintainer repro (#7185): quotemeta() must TypeError on enum case operands (php-src Z_PARAM_STR).
enum E: string { case A = 'x'; }
try {
    quotemeta(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
