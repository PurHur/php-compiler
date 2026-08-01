--TEST--
stdlib array_keys/values/unique/flip Zend stub named params (#23274)
--FILE--
<?php
$namesOf = static function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    return implode(',', $names);
};
echo $namesOf('array_keys'), "\n";
echo $namesOf('array_values'), "\n";
echo $namesOf('array_unique'), "\n";
echo $namesOf('array_flip'), "\n";
var_dump(array_keys(array: ['a' => 1, 'b' => 2]));
var_dump(array_keys(array: ['a' => 1, 'b' => 2], filter_value: 2));
var_dump(array_values(array: ['a' => 1, 'b' => 2]));
var_dump(array_unique(array: [1, 1, 2], flags: SORT_NUMERIC));
var_dump(array_flip(array: ['a' => 1]));
try {
    array_keys(input: ['a' => 1]);
    echo "input accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array,filter_value,strict
array
array,flags
array
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
array(1) {
  [0]=>
  string(1) "b"
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  int(1)
  [2]=>
  int(2)
}
array(1) {
  [1]=>
  string(1) "a"
}
Unknown named parameter $input
