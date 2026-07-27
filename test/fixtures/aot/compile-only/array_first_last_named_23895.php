<?php
// AOT lint-only: array_first/array_last Zend stub named params (#23895, ext/standard/array.stub.php)
// Requires PHP_COMPILER_PROFILE=8.5 for registration.
echo array_first(array: [10, 20]), "\n";
echo array_last(array: [10, 20]), "\n";
$rf = new ReflectionFunction('array_first');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
