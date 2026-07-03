<?php

declare(strict_types=1);

$p = 0.0;
$c = similar_text(str_repeat('a', 5), str_repeat('a', 4), $p);
echo "count=$c; percent=$p\n";

if (4 !== $c || $p <= 0.0) {
    exit(1);
}

// Variable-form control (must still pass).
$s1 = str_repeat('a', 5);
$s2 = str_repeat('a', 4);
$p2 = 0.0;
$c2 = similar_text($s1, $s2, $p2);
echo "control count=$c2; percent=$p2\n";
if (4 !== $c2 || $p2 <= 0.0) {
    exit(1);
}

echo "ok\n";
