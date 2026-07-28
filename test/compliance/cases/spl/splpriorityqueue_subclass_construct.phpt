--TEST--
SplPriorityQueue subclass construct + overridden compare (#24328, ext/spl/spl_heap.c)
--FILE--
<?php
class SoftQ extends SplPriorityQueue
{
    public function compare($priority1, $priority2): int
    {
        return $priority2 <=> $priority1;
    }
}
$q = new SoftQ();
$q->insert('a', 3);
$q->insert('b', 1);
$q->insert('c', 2);
echo $q->extract(), "\n";
echo get_class($q), "\n";
$plain = new SplPriorityQueue();
$plain->insert('x', 1);
$plain->insert('y', 3);
echo $plain->extract(), "\n";
?>
--EXPECT--
b
SoftQ
y
