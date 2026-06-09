<?php
// Issue #5930 — sscanf() enum case operands must TypeError (ext/standard/sscanf.c)
enum E: string { case A = '42'; }
try {
    sscanf(E::A, '%d');
    echo "ok\n";
} catch (Throwable $e) {
    echo 'sscanf ', $e::class, ': ', $e->getMessage(), "\n";
}
