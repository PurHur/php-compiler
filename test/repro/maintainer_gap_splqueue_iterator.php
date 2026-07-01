<?php

declare(strict_types=1);

$q = new SplQueue();
$q->enqueue(1);
$q->enqueue(2);
$q->enqueue(3);

$q->rewind();
echo $q->valid() ? 'valid_after_rewind=1' : 'valid_after_rewind=0';
echo "\n";
echo 'current='.$q->current()."\n";
echo 'key='.$q->key()."\n";

$q->setIteratorMode(SplDoublyLinkedList::IT_MODE_DELETE | SplQueue::IT_MODE_FIFO);
$drained = [];
foreach ($q as $k => $v) {
    $drained[] = $k.'=>'.$v;
}
echo implode(',', $drained)."\n";
echo 'count_after='.count($q)."\n";

echo (0 === count($q) && '0=>1,0=>2,0=>3' === implode(',', $drained)) ? "ok\n" : "fail\n";
