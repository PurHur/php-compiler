<?php
// Issue #25337 — ternary/elvis as call arg must not corrupt sibling args.
$x = 'C';
echo json_encode(array_merge([1], $x ? [2] : [3])), "\n";
const FLAG = 3;
function twoway(int $a, string $b): string
{
    return "$a:$b";
}
try {
    echo twoway(FLAG, 'C' ?: 'D'), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    setlocale(LC_COLLATE, 'C' ?: 'POSIX');
    echo "setlocale=ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
