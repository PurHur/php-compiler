--TEST--
runtime gc_collect_cycles() — two-node object cycle (#3113)
--FILE--
<?php
class Node { public $next; }
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
echo gc_collect_cycles();
--EXPECT--
2
