--TEST--
ArrayObject flags / iterator-class / getArrayCopy / user-sort reject extra args (#30965)
--FILE--
<?php
$o = new ArrayObject([1, 2]);
$cmp = static fn ($x, $y) => $x <=> $y;
$cases = [
    'getFlags' => fn () => $o->getFlags(1),
    'setFlags' => fn () => $o->setFlags(0, 1),
    'getIteratorClass' => fn () => $o->getIteratorClass(1),
    'setIteratorClass' => fn () => $o->setIteratorClass('ArrayIterator', 1),
    'getArrayCopy' => fn () => $o->getArrayCopy(1),
    'uasort' => fn () => $o->uasort($cmp, 1),
    'uksort' => fn () => $o->uksort($cmp, 1),
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ' ', $e->getMessage(), "\n";
    }
}
echo 'flags_ok=', $o->getFlags(), "\n";
$o->setFlags(0);
echo 'class_ok=', $o->getIteratorClass(), "\n";
$o->setIteratorClass('ArrayIterator');
echo 'copy_ok=', implode(',', $o->getArrayCopy()), "\n";
echo 'uasort_ok=', $o->uasort($cmp) ? '1' : '0', "\n";
echo 'uksort_ok=', $o->uksort($cmp) ? '1' : '0', "\n";
?>
--EXPECT--
getFlags ArrayObject::getFlags() expects exactly 0 arguments, 1 given
setFlags ArrayObject::setFlags() expects exactly 1 argument, 2 given
getIteratorClass ArrayObject::getIteratorClass() expects exactly 0 arguments, 1 given
setIteratorClass ArrayObject::setIteratorClass() expects exactly 1 argument, 2 given
getArrayCopy ArrayObject::getArrayCopy() expects exactly 0 arguments, 1 given
uasort ArrayObject::uasort() expects exactly 1 argument, 2 given
uksort ArrayObject::uksort() expects exactly 1 argument, 2 given
flags_ok=0
class_ok=ArrayIterator
copy_ok=1,2
uasort_ok=1
uksort_ok=1
