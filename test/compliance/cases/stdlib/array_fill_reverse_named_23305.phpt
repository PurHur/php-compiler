--TEST--
stdlib array_fill/array_reverse Reflection/named params (#23305, basic_functions.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('array_fill');
echo 'array_fill=';
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(array_fill(start_index: 0, count: 2, value: 'x'));
echo "\n";
try {
    array_fill(start_key: 0, num: 2, val: 'x');
    echo "legacy fill ok\n";
} catch (Throwable $e) {
    echo 'legacy fill ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rr = new ReflectionFunction('array_reverse');
echo 'array_reverse=';
foreach ($rr->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(array_reverse(array: [1, 2], preserve_keys: true));
echo "\n";
try {
    array_reverse(input: [1, 2], preserve: true);
    echo "legacy reverse ok\n";
} catch (Throwable $e) {
    echo 'legacy reverse ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
array_fill=start_index,count,value,
array (
  0 => 'x',
  1 => 'x',
)
legacy fill ERR=Error: Unknown named parameter $start_key
array_reverse=array,preserve_keys,
array (
  1 => 2,
  0 => 1,
)
legacy reverse ERR=Error: Unknown named parameter $input
