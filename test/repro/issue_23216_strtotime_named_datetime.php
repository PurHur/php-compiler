<?php
// Repro #23216 — strtotime datetime:/baseTimestamp: named parameters (Zend stub names)
$rf = new ReflectionFunction('strtotime');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = strtotime(datetime: '2020-01-01');
$positional = strtotime('2020-01-01');
$ok = ['datetime', 'baseTimestamp'] === $names
    && $named === $positional
    && false !== $named;
echo $ok ? "ok\n" : "fail\n";
