<?php
// AOT lint-only: escapeshellarg/escapeshellcmd Zend stub named params (#23460, ext/standard/exec.c)
echo escapeshellarg(arg: 'a b'), "\n";
echo escapeshellcmd(command: 'ls $a'), "\n";
$rf = new ReflectionFunction('escapeshellarg');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$rf = new ReflectionFunction('escapeshellcmd');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
