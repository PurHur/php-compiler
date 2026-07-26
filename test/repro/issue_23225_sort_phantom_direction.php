<?php
// Repro #23225 — sort/rsort Reflection is array/flags only; reject $direction
$namesOk = true;
foreach (['sort', 'rsort'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['array', 'flags'] !== $names) {
        $namesOk = false;
    }
}
$a = [2, 1];
$namedOk = sort(array: $a, flags: SORT_NUMERIC) && [1, 2] === $a;
$directionRejected = false;
try {
    $b = [2, 1];
    sort(array: $b, direction: 1);
} catch (Throwable $e) {
    $directionRejected = str_contains($e->getMessage(), 'Unknown named parameter $direction');
}
echo ($namesOk && $namedOk && $directionRejected) ? "ok\n" : "fail\n";
