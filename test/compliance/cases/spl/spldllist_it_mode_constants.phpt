--TEST--
SPL SplDoublyLinkedList/SplQueue/SplStack IT_MODE_* class constants (#22350, ext/spl/spl_dllist.c)
--FILE--
<?php
echo defined('SplDoublyLinkedList::IT_MODE_LIFO') ? 'Y' : 'N', "\n";
echo defined('SplQueue::IT_MODE_LIFO') ? 'Y' : 'N', "\n";
echo defined('SplStack::IT_MODE_FIFO') ? 'Y' : 'N', "\n";
echo constant('SplDoublyLinkedList::IT_MODE_LIFO'), "\n";
echo SplDoublyLinkedList::IT_MODE_LIFO, "\n";
echo SplDoublyLinkedList::IT_MODE_FIFO, "\n";
echo SplDoublyLinkedList::IT_MODE_DELETE, "\n";
echo SplDoublyLinkedList::IT_MODE_KEEP, "\n";
$r = new ReflectionClass('SplDoublyLinkedList');
echo $r->getConstant('IT_MODE_LIFO'), "\n";
echo $r->getConstant('IT_MODE_FIFO'), "\n";
echo $r->getConstant('IT_MODE_DELETE'), "\n";
echo $r->getConstant('IT_MODE_KEEP'), "\n";
$c = $r->getConstants();
ksort($c);
foreach ($c as $name => $value) {
    echo $name, '=', $value, "\n";
}
$rq = new ReflectionClass('SplQueue');
$cq = $rq->getConstants();
ksort($cq);
foreach ($cq as $name => $value) {
    echo 'Q:', $name, '=', $value, "\n";
}
?>
--EXPECT--
Y
Y
Y
2
2
0
1
0
2
0
1
0
IT_MODE_DELETE=1
IT_MODE_FIFO=0
IT_MODE_KEEP=0
IT_MODE_LIFO=2
Q:IT_MODE_DELETE=1
Q:IT_MODE_FIFO=0
Q:IT_MODE_KEEP=0
Q:IT_MODE_LIFO=2
