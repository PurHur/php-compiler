--TEST--
array_pop/array_shift Reflection return mixed (#26112, array.stub.php)
--FILE--
<?php
foreach (['array_pop', 'array_shift'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
$a = [1, 2];
echo 'pop=', array_pop($a), "\n";
$b = [3, 4];
echo 'shift=', array_shift($b), "\n";
?>
--EXPECT--
array_pop ret=mixed
array_shift ret=mixed
pop=2
shift=3
