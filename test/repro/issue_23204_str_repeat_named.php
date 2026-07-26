<?php
// Repro #23204 — str_repeat string:/times: named parameters (Zend stub names)
$rf = new ReflectionFunction('str_repeat');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = str_repeat(string: 'x', times: 3);
$positional = str_repeat('x', 3);
$ok = ['string', 'times'] === $names
    && 'xxx' === $named
    && $named === $positional;
echo $ok ? "ok\n" : "fail\n";
