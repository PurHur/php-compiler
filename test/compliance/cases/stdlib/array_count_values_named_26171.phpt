--TEST--
stdlib array_count_values Reflection/named params (#26171, array.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('array_count_values');
echo 'params=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(array_count_values(array: [1, 1, 2]));
echo "\n";
try {
    array_count_values(input: [1, 1, 2]);
    echo "legacy input ok\n";
} catch (Throwable $e) {
    echo 'legacy input ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
params=array,
array (
  1 => 2,
  2 => 1,
)
legacy input ERR=Error: Unknown named parameter $input
