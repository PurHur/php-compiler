--TEST--
var_export/print_r Reflection value + named value:/return: (#23308, basic_functions.stub.php)
--FILE--
<?php
foreach (['var_export', 'print_r'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
echo var_export(value: ['a' => 1], return: true), "\n";
echo print_r(value: [1], return: true);
try {
    var_export(var: 1, return: true);
    echo "var accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
var_export:value,return
print_r:value,return
array (
  'a' => 1,
)
Array
(
    [0] => 1
)
Unknown named parameter $var
