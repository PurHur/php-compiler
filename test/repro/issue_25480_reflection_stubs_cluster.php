<?php
/**
 * Issue #25480 — Reflection stubs vs Zend for array_replace / restore_error_handler / range / dirname.
 * php-src: ext/standard/array.stub.php, basic_functions.stub.php
 */
$r = new ReflectionFunction('array_replace');
$p = $r->getParameters()[1];
echo 'replacements name=', $p->getName(),
    ' opt=', $p->isOptional() ? 'Y' : 'N',
    ' var=', $p->isVariadic() ? 'Y' : 'N',
    ' required=', $r->getNumberOfRequiredParameters(),
    "\n";
var_export(array_replace([1]));
echo "\n";

$r = new ReflectionFunction('restore_error_handler');
echo 'restore ret=', ($r->getReturnType() ?: 'none'), "\n";

$r = new ReflectionFunction('range');
$p = $r->getParameters()[2];
echo 'step type=', ($p->getType() ?: 'none'), "\n";
echo json_encode(range(0, 1, 0.5)), "\n";

$r = new ReflectionFunction('dirname');
$p = $r->getParameters()[1];
echo 'levels type=', ($p->getType() ?: 'none'),
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-',
    "\n";
echo 'dirname=', dirname('/a/b/c'), "\n";
