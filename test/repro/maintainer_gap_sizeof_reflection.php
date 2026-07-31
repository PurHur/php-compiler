<?php
// #25966 — sizeof Reflection matches Zend stubs (share count metadata)
$r = new ReflectionFunction('sizeof');
echo 'hasRet=', $r->hasReturnType() ? '1' : '0', "\n";
if ($r->hasReturnType()) {
    echo 'ret=', $r->getReturnType(), "\n";
}
echo 'nParams=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' typed=', $p->hasType() ? '1' : '0',
        ' type=', $p->hasType() ? (string) $p->getType() : '(none)',
        ' opt=', $p->isOptional() ? '1' : '0',
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '(none)',
        "\n";
}
// Named mode: still works; runtime alias still Zend-aligned
echo 'named=', sizeof(value: [1, 2], mode: 0), "\n";
