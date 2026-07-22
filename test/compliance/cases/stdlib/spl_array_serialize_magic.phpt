--TEST--
SPL ArrayObject/ArrayIterator __serialize/__unserialize (#22269)
--FILE--
<?php
$a = new ArrayObject([1, 2], ArrayObject::ARRAY_AS_PROPS);
$i = new ArrayIterator([1]);
echo method_exists($a, '__serialize') ? 'AOY' : 'AON', "\n";
echo method_exists($i, '__serialize') ? 'AIY' : 'AIN', "\n";
$bag = $a->__serialize();
echo implode(',', array_keys($bag)), "\n";
echo $bag[0], "\n";
echo json_encode($bag[1]), "\n";
echo is_array($bag[2]) ? 'members' : 'bad', "\n";
echo null === $bag[3] ? 'nulliter' : 'bad', "\n";

$b = new ArrayObject([]);
$b->__unserialize($bag);
echo json_encode($b->getArrayCopy()), "\n";
echo $b->getFlags(), "\n";

$ib = $i->__serialize();
echo json_encode($ib[1]), "\n";
$j = new ArrayIterator([]);
$j->__unserialize($ib);
echo json_encode($j->getArrayCopy()), "\n";

$wire = serialize($a);
$u = unserialize($wire);
echo $u instanceof ArrayObject ? 'Y' : 'N', "\n";
echo json_encode($u->getArrayCopy()), "\n";

try {
    $b->__unserialize('x');
} catch (TypeError $e) {
    echo 'type', "\n";
}
try {
    $b->__unserialize([0 => 0]);
} catch (UnexpectedValueException $e) {
    echo 'ill', "\n";
}
--EXPECT--
AOY
AIY
0,1,2,3
2
[1,2]
members
nulliter
[1,2]
2
[1]
[1]
Y
[1,2]
type
ill
