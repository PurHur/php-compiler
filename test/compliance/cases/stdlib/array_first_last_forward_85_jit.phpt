--TEST--
stdlib array_first()/array_last() JIT — PHP 8.5 forward profile (#21173, #23895, ext/standard/array.c)
--JIT--
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo 'array_first=', function_exists('array_first') ? 'yes' : 'no', "\n";
echo 'array_last=', function_exists('array_last') ? 'yes' : 'no', "\n";
$a = ['x' => 1, 'y' => 2];
var_export(array_first($a));
echo "\n";
var_export(array_last($a));
echo "\n";
$list = [10, 20, 30];
var_export(array_first($list));
echo "\n";
var_export(array_last($list));
echo "\n";
foreach (['array_first', 'array_last'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ' params=[', implode(',', $names), '] n=', $rf->getNumberOfParameters(), "\n";
}
var_export(array_first(array: [10, 20]));
echo "\n";
var_export(array_last(array: [10, 20]));
echo "\n";
--EXPECT--
array_first=yes
array_last=yes
1
2
10
30
array_first params=[array] n=1
array_last params=[array] n=1
10
20
