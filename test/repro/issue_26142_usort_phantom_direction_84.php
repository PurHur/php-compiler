<?php
// Repro #26142 — PROFILE=8.4 must match Zend: usort arity 2, reject $direction
foreach (['usort', 'uksort', 'uasort'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=n', $r->getNumberOfParameters(), ' names=',
        implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
$a = [3, 1, 2];
try {
    usort($a, static fn ($x, $y) => $x <=> $y, SortDirection::Ascending);
    echo "positional=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    usort(array: $a, callback: static fn ($x, $y) => $x <=> $y, direction: SortDirection::Ascending);
    echo "named=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$ok = [1, 2, 3];
usort($ok, static fn ($x, $y) => $x <=> $y);
echo 'sort=', implode(',', $ok), "\n";
