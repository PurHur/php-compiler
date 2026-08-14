--TEST--
MultipleIterator attach/detach/contains/getFlags reject extra args (#30947)
--FILE--
<?php
$m = new MultipleIterator();
$a = new ArrayIterator([1]);
$cases = [
    'attach' => fn () => $m->attachIterator($a, null, 'x'),
    'detach' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);
        $m2->detachIterator($a, 'x');
    },
    'contains' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);
        return $m2->containsIterator($a, 'x');
    },
    'getFlags' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);
        return $m2->getFlags('x');
    },
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ' ', $e->getMessage(), "\n";
    }
}
$ok = new MultipleIterator();
$ok->attachIterator($a, null);
echo 'attach_ok=', $ok->containsIterator($a) ? '1' : '0', "\n";
echo 'flags_ok=', $ok->getFlags(), "\n";
$ok->detachIterator($a);
echo 'detach_ok=', $ok->containsIterator($a) ? '1' : '0', "\n";
?>
--EXPECT--
attach MultipleIterator::attachIterator() expects at most 2 arguments, 3 given
detach MultipleIterator::detachIterator() expects exactly 1 argument, 2 given
contains MultipleIterator::containsIterator() expects exactly 1 argument, 2 given
getFlags MultipleIterator::getFlags() expects exactly 0 arguments, 1 given
attach_ok=1
flags_ok=1
detach_ok=0
