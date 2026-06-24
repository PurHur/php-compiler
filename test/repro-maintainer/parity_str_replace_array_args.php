<?php

declare(strict_types=1);

$r1 = str_replace(['a', 'b'], ['A', 'B'], 'ab');
$r2 = str_ireplace(['A'], ['x'], 'a');
$r3 = str_replace('a', 'A', ['a' => 'x']);

if ('AB' !== $r1) {
    fwrite(STDERR, 'FAIL: str_replace array search/replace expected AB, got '.var_export($r1, true)."\n");
    exit(1);
}
if ('x' !== $r2) {
    fwrite(STDERR, 'FAIL: str_ireplace array control expected x, got '.var_export($r2, true)."\n");
    exit(1);
}
if (!\is_array($r3) || 'x' !== ($r3['a'] ?? null)) {
    fwrite(STDERR, 'FAIL: str_replace array subject expected [a=>x], got '.var_export($r3, true)."\n");
    exit(1);
}

echo "OK\n";
