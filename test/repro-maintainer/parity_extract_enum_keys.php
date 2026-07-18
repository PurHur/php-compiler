<?php
/**
 * #5756 — extract() with enum case array keys must TypeError (Illegal offset type).
 * Zend rejects the key at array construction; VM must match.
 */
enum E { case A; }

try {
    extract([E::A => 1]);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
