--TEST--
stdlib array_change_key_case array: named params (#25500)
--FILE--
<?php
$r = new ReflectionFunction('array_change_key_case');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
var_dump(array_change_key_case(array: ['Foo' => 1], case: CASE_UPPER));
var_dump(array_change_key_case(array: ['Bar' => 2]));
try {
    array_change_key_case(input: ['X' => 1]);
    echo "input accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array,case
array(1) {
  ["FOO"]=>
  int(1)
}
array(1) {
  ["bar"]=>
  int(2)
}
Unknown named parameter $input
