<?php
// #36221 program: SPL stack/queue/object storage
$stack = new SplStack();
foreach ([1, 2, 3] as $n) { $stack->push($n); }
$stackBits = [];
foreach ($stack as $v) { $stackBits[] = $v; }
$q = new SplQueue();
$q->enqueue('a');
$q->enqueue('b');
$q->enqueue('c');
$qBits = [];
while (!$q->isEmpty()) { $qBits[] = $q->dequeue(); }
$ao = new ArrayObject(['z' => 1, 'a' => 2]);
$ao['m'] = 3;
$aoKeys = array_keys($ao->getArrayCopy());
sort($aoKeys);
$out = 'stack=' . implode(',', $stackBits)
    . '|queue=' . implode(',', $qBits)
    . '|ao=' . implode(',', $aoKeys)
    . '|aov=' . implode(',', array_values($ao->getArrayCopy()))
    . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
