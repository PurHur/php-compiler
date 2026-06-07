<?php
declare(strict_types=1);

/** Issue #7216 — defined() TypeError parameter name must be $constant_name (php-src Z_PARAM_STR). */
enum E: string { case A = 'x'; }

try {
    defined(E::A);
} catch (TypeError $e) {
    echo $e->getMessage() . "\n";
}
