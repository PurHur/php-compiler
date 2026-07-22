--TEST--
ParentIterator under RecursiveIteratorIterator nested new (#22007)
--FILE--
<?php
$data = [1, [2, 3], 4];

$n = 0;
foreach (
    new RecursiveIteratorIterator(
        new ParentIterator(new RecursiveArrayIterator($data)),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $k => $v
) {
    echo 'self k=', $k, ' arr=', is_array($v) ? '1' : '0', "\n";
    $n++;
}
echo 'self_count=', $n, "\n";

$n = 0;
foreach (
    new RecursiveIteratorIterator(
        new ParentIterator(new RecursiveArrayIterator($data)),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $k => $v
) {
    echo 'child k=', $k, ' arr=', is_array($v) ? '1' : '0', "\n";
    $n++;
}
echo 'child_count=', $n, "\n";

// Bound ParentIterator then wrap — already worked; keep green.
$pi = new ParentIterator(new RecursiveArrayIterator($data));
$n = 0;
foreach (new RecursiveIteratorIterator($pi, RecursiveIteratorIterator::SELF_FIRST) as $k => $v) {
    $n++;
}
echo 'bound_count=', $n, "\n";

// Manual ParentIterator alone
$pi2 = new ParentIterator(new RecursiveArrayIterator($data));
$pi2->rewind();
$manual = 0;
while ($pi2->valid()) {
    $manual++;
    $pi2->next();
}
echo 'manual=', $manual, "\n";
?>
--EXPECT--
self k=1 arr=1
self_count=1
child k=1 arr=1
child_count=1
bound_count=1
manual=1
