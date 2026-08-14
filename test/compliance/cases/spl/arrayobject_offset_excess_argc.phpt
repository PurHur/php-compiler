--TEST--
ArrayObject ArrayAccess methods reject extra args (#31001)
--FILE--
<?php
$o = new ArrayObject([1, 2]);
$cases = [
    'offsetExists' => fn () => $o->offsetExists(0, 1),
    'offsetGet' => fn () => $o->offsetGet(0, 1),
    'offsetSet' => fn () => $o->offsetSet(0, 9, 1),
    'offsetUnset' => fn () => $o->offsetUnset(1, 1),
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ' ', $e->getMessage(), "\n";
    }
}
echo 'exists_ok=', $o->offsetExists(0) ? '1' : '0', "\n";
echo 'get_ok=', $o->offsetGet(0), "\n";
$o->offsetSet(0, 9);
echo 'set_ok=', $o->offsetGet(0), "\n";
$o->offsetUnset(1);
echo 'unset_ok=', $o->offsetExists(1) ? '1' : '0', "\n";
?>
--EXPECT--
offsetExists ArrayObject::offsetExists() expects exactly 1 argument, 2 given
offsetGet ArrayObject::offsetGet() expects exactly 1 argument, 2 given
offsetSet ArrayObject::offsetSet() expects exactly 2 arguments, 3 given
offsetUnset ArrayObject::offsetUnset() expects exactly 1 argument, 2 given
exists_ok=1
get_ok=1
set_ok=9
unset_ok=0
