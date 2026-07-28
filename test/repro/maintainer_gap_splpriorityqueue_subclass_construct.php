<?php
class SoftQ extends SplPriorityQueue
{
    public function compare($priority1, $priority2): int
    {
        // Min-heap (reverse of default max-heap ordering).
        return $priority2 <=> $priority1;
    }
}
$q = new SoftQ();
$q->insert('a', 3);
$q->insert('b', 1);
$q->insert('c', 2);
echo $q->extract(), "\n";
echo get_class($q), "\n";
