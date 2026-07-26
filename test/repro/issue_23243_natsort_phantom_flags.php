<?php
// Repro #23243 — natsort/natcasesort Reflection is array only; reject $flags
$namesOk = true;
foreach (['natsort', 'natcasesort'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['array'] !== $names) {
        $namesOk = false;
    }
}
$a = ['10', '2'];
$namedOk = natsort(array: $a) && ['2', '10'] === array_values($a);
$b = ['B', 'a'];
$namedOk = $namedOk && natcasesort(array: $b) && ['a', 'B'] === array_values($b);
$flagsRejected = false;
try {
    $c = ['10', '2'];
    natsort($c, flags: 0);
} catch (Throwable $e) {
    $flagsRejected = str_contains($e->getMessage(), 'Unknown named parameter $flags');
}
echo ($namesOk && $namedOk && $flagsRejected) ? "ok\n" : "fail\n";
