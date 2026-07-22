--TEST--
stdlib SplDoublyLinkedList/SplQueue/SplStack __serialize/__unserialize (#22287, ext/spl/spl_dllist.c)
--FILE--
<?php
foreach (['SplDoublyLinkedList', 'SplQueue', 'SplStack'] as $c) {
    $o = new $c();
    echo $c,
        ' __serialize=', method_exists($o, '__serialize') ? 'Y' : 'N',
        ' serialize=', method_exists($o, 'serialize') ? 'Y' : 'N',
        "\n";
}
$d = new SplDoublyLinkedList();
$d->push('a');
$d->push(2);
$bag = $d->__serialize();
echo 'bag0=', (string) $bag[0], ' bag1=', implode(',', $bag[1]), "\n";
$d2 = new SplDoublyLinkedList();
$d2->__unserialize($bag);
echo 'count=', $d2->count(), ' values=';
$vals = [];
foreach ($d2 as $v) {
    $vals[] = (string) $v;
}
echo implode(',', $vals), "\n";
$s = serialize($d);
$u = unserialize($s);
echo 'legacy_count=', $u->count(), "\n";
$q = new SplQueue();
$q->enqueue('x');
$qbag = $q->__serialize();
echo 'queue_mode=', (string) $qbag[0], ' queue_val=', $qbag[1][0], "\n";
?>
--EXPECT--
SplDoublyLinkedList __serialize=Y serialize=Y
SplQueue __serialize=Y serialize=Y
SplStack __serialize=Y serialize=Y
bag0=0 bag1=a,2
count=2 values=a,2
legacy_count=2
queue_mode=4 queue_val=x
