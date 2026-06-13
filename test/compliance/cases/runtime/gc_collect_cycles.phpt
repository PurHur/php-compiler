--TEST--
runtime gc_collect_cycles() — two-node object cycle (#3113)
--FILE--
<?php
#[\AllowDynamicProperties]
class Node {
}
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
echo gc_collect_cycles();
--EXPECT--
2
