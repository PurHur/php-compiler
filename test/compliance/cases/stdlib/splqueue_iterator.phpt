--TEST--
SplQueue Iterator — rewind/valid/current/key/foreach IT_MODE_DELETE (ext/spl/spl_dllist.c; #14261)
--FILE--
<?php
$q = new SplQueue();
$q->enqueue(1);
$q->enqueue(2);
$q->enqueue(3);
$q->rewind();
echo $q->valid() ? "valid=1\n" : "valid=0\n";
echo 'current=', $q->current(), "\n";
echo 'key=', $q->key(), "\n";
$q->setIteratorMode(SplDoublyLinkedList::IT_MODE_DELETE | SplQueue::IT_MODE_FIFO);
$drained = [];
foreach ($q as $k => $v) {
    $drained[] = $k . '=>' . $v;
}
echo implode(',', $drained), "\n";
echo 'count=', count($q), "\n";
?>
--EXPECT--
valid=1
current=1
key=0
0=>1,0=>2,0=>3
count=0
