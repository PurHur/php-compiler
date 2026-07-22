--TEST--
RecursiveArrayIterator ArrayAccess/sort/seek/flags/serialize (#22286)
--FILE--
<?php
$r = new RecursiveArrayIterator(['x' => 1, 'y' => [2, 3], 'z' => 4]);
foreach (['offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', 'append', 'asort', 'ksort', 'natsort', 'natcasesort', 'uasort', 'uksort', 'seek', 'getFlags', 'setFlags', 'serialize', 'unserialize', '__serialize', '__unserialize'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'Y' : 'N', "\n";
}
echo 'dim=', $r['x'], "\n";
echo 'isset_x=', isset($r['x']) ? 'Y' : 'N', "\n";
$r['w'] = 9;
echo 'dim_w=', $r['w'], "\n";
$r->offsetUnset('w');
$copy = $r->getArrayCopy();
echo 'after_unset=', array_key_exists('w', $copy) ? 'Y' : 'N', "\n";
$r->append(10);
echo 'count_after_append=', $r->count(), "\n";
echo 'flags0=', $r->getFlags(), "\n";
$r->setFlags(ArrayIterator::ARRAY_AS_PROPS);
echo 'flags1=', $r->getFlags(), "\n";
$r->seek(1);
echo 'seek_key=', $r->key(), "\n";
echo 'seek_has=', $r->hasChildren() ? 'Y' : 'N', "\n";
$s = $r->serialize();
echo 'ser=', is_string($s) && strlen($s) > 0 ? 'Y' : 'N', "\n";
$bag = $r->__serialize();
echo 'bag0=', is_int($bag[0]) ? 'Y' : 'N', "\n";
echo 'bag1=', is_array($bag[1]) ? 'Y' : 'N', "\n";
$r3 = new RecursiveArrayIterator(['b' => 2, 'a' => 1]);
$r3->asort();
echo 'asort=', json_encode($r3->getArrayCopy()), "\n";
$r3->ksort();
echo 'ksort=', json_encode($r3->getArrayCopy()), "\n";
$prev = $r3->count();
echo 'count_copy=', $r3->count() === count($r3->getArrayCopy()) ? 'Y' : 'N', "\n";
echo 'prev=', $prev, "\n";
--EXPECT--
offsetGet=Y
offsetSet=Y
offsetExists=Y
offsetUnset=Y
append=Y
asort=Y
ksort=Y
natsort=Y
natcasesort=Y
uasort=Y
uksort=Y
seek=Y
getFlags=Y
setFlags=Y
serialize=Y
unserialize=Y
__serialize=Y
__unserialize=Y
dim=1
isset_x=Y
dim_w=9
after_unset=N
count_after_append=4
flags0=0
flags1=2
seek_key=y
seek_has=Y
ser=Y
bag0=Y
bag1=Y
asort={"a":1,"b":2}
ksort={"a":1,"b":2}
count_copy=Y
prev=2
