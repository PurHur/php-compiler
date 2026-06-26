<?php
declare(strict_types=1);

$funcs = ['array_find', 'array_find_key', 'array_any', 'array_all', 'array_first', 'array_last'];
$missing = [];
foreach ($funcs as $fn) {
    if (!function_exists($fn)) {
        $missing[] = $fn;
    }
}
if ([] !== $missing) {
    echo 'missing='.implode(',', $missing)."\n";
    exit(1);
}

$a = [1, 2, 3];
echo array_find($a, fn ($v) => $v === 2), "\n";
echo array_find_key($a, fn ($v) => $v === 2), "\n";
echo array_any($a, fn ($v) => $v > 2) ? 'any' : 'notany', "\n";
echo array_all($a, fn ($v) => $v > 0) ? 'all' : 'notall', "\n";
echo array_first($a), "\n";
echo array_last($a), "\n";
