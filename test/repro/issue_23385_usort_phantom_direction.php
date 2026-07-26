<?php
// Repro #23385 — usort/uksort/uasort Reflection is array/callback only on 8.2 profile; reject $direction
$namesOk = true;
foreach (['usort', 'uksort', 'uasort'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = array_map(static fn ($p) => $p->getName(), $r->getParameters());
    if ($names !== ['array', 'callback'] || 2 !== $r->getNumberOfParameters()) {
        $namesOk = false;
    }
}
$a = [3, 1];
$namedOk = false;
usort(array: $a, callback: static fn ($x, $y) => $x <=> $y);
$namedOk = [1, 3] === $a;
$directionRejected = false;
try {
    $b = [3, 1];
    usort(array: $b, callback: static fn ($x, $y) => $x <=> $y, direction: 1);
} catch (Throwable $e) {
    $directionRejected = str_contains($e->getMessage(), 'Unknown named parameter $direction');
}
echo ($namesOk && $namedOk && $directionRejected) ? "ok\n" : "fail\n";
