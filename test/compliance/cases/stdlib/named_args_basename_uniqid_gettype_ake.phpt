--TEST--
basename/uniqid/gettype/array_key_exists named arguments (VM, issue #23193)
--FILE--
<?php
echo basename(path: '/a/b.txt', suffix: '.txt'), PHP_EOL;
echo substr(uniqid(prefix: 'x', more_entropy: true), 0, 1), PHP_EOL;
var_export(gettype(value: []));
echo PHP_EOL;
var_export(array_key_exists(key: 'a', array: ['a' => 1]));
echo PHP_EOL;
foreach (['gettype', 'array_key_exists'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), PHP_EOL;
}
--EXPECT--
b
x
'array'
true
gettype:value
array_key_exists:key,array
