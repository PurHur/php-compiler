<?php
/**
 * Repro for #16987 / #16986 — enum case array keys must throw at write (zend_hash.c).
 * Zend never reaches array_keys(); VM must reject illegal offsets like php-src.
 */
declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

try {
    $a = [];
    $a[E::A] = 'x';
    $a[E::B] = 'y';
    fwrite(STDERR, "accepted enum keys\n");
    exit(1);
} catch (TypeError $e) {
    if ($e->getMessage() !== 'Illegal offset type') {
        fwrite(STDERR, 'unexpected: ' . $e->getMessage() . "\n");
        exit(2);
    }
    echo "ok\n";
    exit(0);
}
