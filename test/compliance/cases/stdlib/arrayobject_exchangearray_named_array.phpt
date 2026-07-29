--TEST--
SPL ArrayObject::exchangeArray Reflection/named array (#24493)
--FILE--
<?php
$r = new ReflectionMethod('ArrayObject', 'exchangeArray');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
$ao = new ArrayObject(['a' => 1]);
$prev = $ao->exchangeArray(array: ['b' => 2]);
echo 'prev=', json_encode($prev), ' now=', json_encode($ao->getArrayCopy()), "\n";
try {
    $ao->exchangeArray(ar: ['c' => 3]);
    echo "ar=accepted\n";
} catch (Error $e) {
    echo "ar=rejected\n";
}
--EXPECT--
params=array
prev={"a":1} now={"b":2}
ar=rejected
