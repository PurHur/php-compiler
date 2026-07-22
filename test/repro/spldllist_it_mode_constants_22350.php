<?php
// repro: SplDoublyLinkedList/SplQueue/SplStack IT_MODE_* discovery (#22350)
echo 'defined_dll=', defined('SplDoublyLinkedList::IT_MODE_LIFO') ? 'Y' : 'N', "\n";
echo 'defined_q=', defined('SplQueue::IT_MODE_LIFO') ? 'Y' : 'N', "\n";
echo 'defined_s=', defined('SplStack::IT_MODE_FIFO') ? 'Y' : 'N', "\n";
$r = new ReflectionClass('SplDoublyLinkedList');
$c = $r->getConstants();
ksort($c);
echo 'dll_consts=', json_encode($c), "\n";
$rq = new ReflectionClass('SplQueue');
$cq = $rq->getConstants();
ksort($cq);
echo 'q_consts=', json_encode($cq), "\n";
$rs = new ReflectionClass('SplStack');
$cs = $rs->getConstants();
ksort($cs);
echo 's_consts=', json_encode($cs), "\n";
echo 'lifo=', SplDoublyLinkedList::IT_MODE_LIFO, "\n";
echo 'fifo=', SplDoublyLinkedList::IT_MODE_FIFO, "\n";
echo 'del=', SplDoublyLinkedList::IT_MODE_DELETE, "\n";
echo 'keep=', SplDoublyLinkedList::IT_MODE_KEEP, "\n";
echo 'const_fn=', constant('SplDoublyLinkedList::IT_MODE_LIFO'), "\n";
echo 'getConstant=', (new ReflectionClass('SplDoublyLinkedList'))->getConstant('IT_MODE_LIFO'), "\n";
