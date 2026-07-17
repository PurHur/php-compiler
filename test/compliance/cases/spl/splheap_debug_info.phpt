--TEST--
SPL SplHeap/SplPriorityQueue::__debugInfo() — var_dump shows flags+heap (#19825, ext/spl/spl_heap.c)
--FILE--
<?php
$h = new SplMinHeap();
$h->insert(3);
$h->insert(1);
ob_start();
var_dump($h);
$vd = ob_get_clean();
echo (str_contains($vd, 'object(SplMinHeap)') && str_contains($vd, 'flags') && str_contains($vd, 'isCorrupted')
    && str_contains($vd, 'heap') && str_contains($vd, 'int(1)') && str_contains($vd, 'int(3)')
    && str_contains($vd, ':private'))
    ? "minheap_ok\n" : "minheap_fail\n";

$p = new SplPriorityQueue();
$p->insert('a', 1);
$p->insert('b', 2);
ob_start();
var_dump($p);
$vp = ob_get_clean();
echo (str_contains($vp, 'object(SplPriorityQueue)') && str_contains($vp, 'flags') && str_contains($vp, 'int(1)')
    && str_contains($vp, 'isCorrupted') && str_contains($vp, 'heap') && str_contains($vp, '["data"]')
    && str_contains($vp, '["priority"]') && str_contains($vp, 'string(1) "b"'))
    ? "priority_ok\n" : "priority_fail\n";

ob_start();
print_r($h);
$pr = ob_get_clean();
echo (str_contains($pr, 'flags:SplHeap:private') && str_contains($pr, 'heap:SplHeap:private'))
    ? "print_r_ok\n" : "print_r_fail\n";
?>
--EXPECT--
minheap_ok
priority_ok
print_r_ok
