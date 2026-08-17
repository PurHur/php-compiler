<?php
/**
 * #28920 — crypt AOT: $salt still required at runtime (crypt.c).
 * Reflection metadata is exercised on VM; this guards ArgumentCountError under native AOT.
 */
try {
    crypt('x');
    echo "argc=ok\n";
} catch (ArgumentCountError $e) {
    echo "argc=ArgumentCountError\n";
}
$hash = crypt('x', 'rl');
echo 'hash_ok=', is_string($hash) && $hash !== '' && !str_starts_with($hash, '*') ? '1' : '0', "\n";
