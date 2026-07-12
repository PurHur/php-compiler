--TEST--
ReflectionMethod::getClosureUsedVariables() on ordinary method returns empty array (#18227)
--FILE--
<?php
declare(strict_types=1);

$rm = new ReflectionMethod('DateTime', 'format');
echo method_exists($rm, 'getClosureUsedVariables') ? "method_yes\n" : "method_no\n";
var_export($rm->getClosureUsedVariables());
echo "\n";
--EXPECT--
method_yes
array (
)
