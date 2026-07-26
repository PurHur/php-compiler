--TEST--
function_exists named function argument (VM, issue #23435)
--FILE--
<?php
var_export(function_exists(function: 'strlen'));
echo PHP_EOL;
$rf = new ReflectionFunction('function_exists');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'function_exists:', implode(',', $names), PHP_EOL;
try {
    function_exists(function_name: 'strlen');
    echo "function_name accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
true
function_exists:function
Unknown named parameter $function_name
