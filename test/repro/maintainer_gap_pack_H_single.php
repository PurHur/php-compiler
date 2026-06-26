<?php

declare(strict_types=1);

// php-src ext/standard/pack.c — odd-length H/h hex (#12217).
$single = pack('H', '4142');
if ('@' !== $single) {
    fwrite(STDERR, "pack('H', '4142'): expected '@', got ".var_export($single, true)."\n");
    exit(1);
}

$h2 = pack('H2', '4142');
if ('A' !== $h2) {
    fwrite(STDERR, "pack('H2', '4142'): expected 'A', got ".var_export($h2, true)."\n");
    exit(1);
}

$star = pack('H*', '4142');
if ('AB' !== $star) {
    fwrite(STDERR, "pack('H*', '4142'): expected 'AB', got ".var_export($star, true)."\n");
    exit(1);
}

$low = pack('h', '4142');
if ("\x04" !== $low) {
    fwrite(STDERR, "pack('h', '4142'): expected \\x04, got ".bin2hex($low)."\n");
    exit(1);
}

echo "ok\n";
