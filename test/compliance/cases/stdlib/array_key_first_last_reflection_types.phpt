--TEST--
array_key_first / array_key_last Reflection array→string|int|null (#26111, ext/standard/array.stub.php)
--FILE--
<?php
declare(strict_types=1);
foreach (['array_key_first', 'array_key_last'] as $f) {
    $r = new ReflectionFunction($f);
    $p = $r->getParameters()[0];
    echo $f,
        ' typed=', $p->hasType() ? (string) $p->getType() : 'no',
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none',
        "\n";
}
echo 'first=', array_key_first(array: ['x' => 1]), ' last=', array_key_last(array: ['x' => 1, 'y' => 2]), "\n";
--EXPECT--
array_key_first typed=array ret=string|int|null
array_key_last typed=array ret=string|int|null
first=x last=y
