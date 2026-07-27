--TEST--
stdlib compact() variadic named $var_name — overwrite Error (#23803, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('compact');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'reflection:', implode(',', $names), "\n";
$a = 1;
$b = 2;
try {
    compact(var_name: 'a', var_name: 'b');
    echo "named: no error\n";
} catch (Throwable $e) {
    echo 'named:', get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(compact('a', 'b'));
echo "\n";
--EXPECT--
reflection:var_name,var_names
named:Error: Named parameter $var_name overwrites previous argument
array (
  'a' => 1,
  'b' => 2,
)
