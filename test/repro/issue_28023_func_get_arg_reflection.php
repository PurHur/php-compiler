<?php
// #28023 — func_get_arg Reflection return mixed (Zend/zend_builtin_functions.stub.php)
$rf = new ReflectionFunction('func_get_arg');
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '<none>', "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ' ', $p->hasType() ? (string) $p->getType() : '<none>', "\n";
}
