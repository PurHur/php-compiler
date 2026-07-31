--TEST--
stdlib array_is_list/array_key_first/array_key_last array: named params (#23262)
--FILE--
<?php
foreach (['array_is_list', 'array_key_first', 'array_key_last'] as $f) {
    $r = new ReflectionFunction($f);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, '=', implode(',', $names), "\n";
}
var_dump(array_is_list(array: [0, 1]));
var_dump(array_key_first(array: ['a' => 1, 'b' => 2]));
var_dump(array_key_last(array: ['a' => 1, 'b' => 2]));
try {
    array_is_list(input: [0]);
    echo "input accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_is_list=array
array_key_first=array
array_key_last=array
bool(true)
string(1) "a"
string(1) "b"
Unknown named parameter $input
