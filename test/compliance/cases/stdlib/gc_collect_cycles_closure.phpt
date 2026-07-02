--TEST--
stdlib gc_collect_cycles() via user Closure — collected count (#14827)
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
$collect = static function (): int {
    return gc_collect_cycles();
};
echo $collect(), "\n";
?>
--EXPECT--
2
