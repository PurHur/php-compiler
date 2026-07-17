--TEST--
SPL SplDoublyLinkedList::__debugInfo() — var_dump shows flags+dllist (#19824, ext/spl/spl_dllist.c)
--FILE--
<?php
$d = new SplDoublyLinkedList();
$d->push(10);
$d->push(20);
ob_start();
var_dump($d);
$vd = ob_get_clean();
echo (str_contains($vd, 'flags') && str_contains($vd, 'dllist') && str_contains($vd, 'int(10)')
    && str_contains($vd, 'int(20)') && str_contains($vd, ':private'))
    ? "dllist_var_dump_ok\n" : "dllist_var_dump_fail\n";

$q = new SplQueue();
$q->enqueue(1);
ob_start();
var_dump($q);
$vq = ob_get_clean();
echo (str_contains($vq, 'object(SplQueue)') && str_contains($vq, 'flags') && str_contains($vq, 'dllist')
    && str_contains($vq, 'int(4)') && str_contains($vq, 'int(1)'))
    ? "queue_ok\n" : "queue_fail\n";

$s = new SplStack();
$s->push(2);
ob_start();
var_dump($s);
$vs = ob_get_clean();
echo (str_contains($vs, 'object(SplStack)') && str_contains($vs, 'flags') && str_contains($vs, 'dllist')
    && str_contains($vs, 'int(6)') && str_contains($vs, 'int(2)'))
    ? "stack_ok\n" : "stack_fail\n";

ob_start();
print_r($d);
$pr = ob_get_clean();
echo (str_contains($pr, 'flags:SplDoublyLinkedList:private') && str_contains($pr, 'dllist:SplDoublyLinkedList:private')
    && str_contains($pr, '[0] => 10'))
    ? "print_r_ok\n" : "print_r_fail\n";
?>
--EXPECT--
dllist_var_dump_ok
queue_ok
stack_ok
print_r_ok
