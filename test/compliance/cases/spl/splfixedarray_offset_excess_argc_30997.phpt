--TEST--
SplFixedArray ArrayAccess excess argc (#30997)
--FILE--
<?php
$f = SplFixedArray::fromArray([10, 20, 30]);
foreach ([
    ['offsetGet', static fn ($o) => $o->offsetGet(0, 1)],
    ['offsetSet', static fn ($o) => $o->offsetSet(0, 11, 1)],
    ['offsetExists', static fn ($o) => $o->offsetExists(0, 1)],
    ['offsetUnset', static fn ($o) => $o->offsetUnset(1, 1)],
] as [$name, $fn]) {
    try {
        $fn($f);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
echo 'get=', $f->offsetGet(0), "\n";
echo 'exists=', $f->offsetExists(1) ? '1' : '0', "\n";
$f->offsetSet(0, 11);
$f->offsetUnset(2);
echo 'after=', $f->offsetGet(0), ',', $f->offsetExists(2) ? '1' : '0', "\n";
?>
--EXPECT--
offsetGet SplFixedArray::offsetGet() expects exactly 1 argument, 2 given
offsetSet SplFixedArray::offsetSet() expects exactly 2 arguments, 3 given
offsetExists SplFixedArray::offsetExists() expects exactly 1 argument, 2 given
offsetUnset SplFixedArray::offsetUnset() expects exactly 1 argument, 2 given
get=10
exists=1
after=11,0
