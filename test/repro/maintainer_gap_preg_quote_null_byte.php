<?php

declare(strict_types=1);

$quoted = preg_quote("a\0b");
$expected = 'a\\000b';
if ($quoted !== $expected) {
    fwrite(STDERR, "expected {$expected}, got {$quoted}\n");
    exit(1);
}
if (strlen($quoted) !== 6) {
    fwrite(STDERR, 'expected length 6, got '.strlen($quoted)."\n");
    exit(1);
}
echo "ok\n";
