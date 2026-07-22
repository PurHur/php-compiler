--TEST--
SPL object-storage __serialize/__unserialize (#22268)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
$s->attach($o, 'info');
echo method_exists($s, '__serialize') ? 'Y' : 'N', "\n";
echo method_exists($s, '__unserialize') ? 'Y' : 'N', "\n";
$bag = $s->__serialize();
echo implode(',', array_keys($bag)), "\n";
echo is_array($bag[0]) ? 'storage' : 'bad', "\n";
echo is_array($bag[1]) ? 'members' : 'bad', "\n";
echo $bag[0][0] === $o ? 'obj' : 'bad', "\n";
echo $bag[0][1], "\n";

$t = new SplObjectStorage();
$t->__unserialize($bag);
echo $t->count(), "\n";
echo $t->offsetGet($o), "\n";

$wire = serialize($s);
$u = unserialize($wire);
echo $u instanceof SplObjectStorage ? 'Y' : 'N', "\n";
echo $u->count(), "\n";

$legacy = $s->serialize();
echo is_string($legacy) && $legacy !== '' ? 'legacy' : 'bad', "\n";

try {
    $t->__unserialize('x');
} catch (TypeError $e) {
    echo 'type', "\n";
}
try {
    $t->__unserialize([]);
} catch (UnexpectedValueException $e) {
    echo 'ill', "\n";
}
try {
    $t->__unserialize([0 => [new stdClass()], 1 => []]);
} catch (UnexpectedValueException $e) {
    echo 'odd', "\n";
}
--EXPECT--
Y
Y
0,1
storage
members
obj
info
1
info
Y
1
legacy
type
ill
odd
