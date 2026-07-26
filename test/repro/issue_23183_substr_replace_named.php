<?php
// Repro #23183 — substr_replace string:/replace:/offset:/length: named parameters (Zend stub names)
$rf = new ReflectionFunction('substr_replace');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = substr_replace(string: 'abcdef', replace: 'X', offset: 2, length: 1);
$positional = substr_replace('abcdef', 'X', 2, 1);
$ok = ['string', 'replace', 'offset', 'length'] === $names
    && 'abXdef' === $named
    && $named === $positional;
echo $ok ? "ok\n" : "fail\n";
