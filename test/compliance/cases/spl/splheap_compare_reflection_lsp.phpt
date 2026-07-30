--TEST--
SplHeap family compare Reflection names/types + subclass LSP (#25555)
--FILE--
<?php
foreach ([SplHeap::class, SplMinHeap::class, SplMaxHeap::class, SplPriorityQueue::class] as $c) {
    $rm = new ReflectionMethod($c, 'compare');
    $parts = [];
    foreach ($rm->getParameters() as $p) {
        $parts[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '-');
    }
    echo $c, ' (', implode(', ', $parts), ")\n";
}

class H extends SplMinHeap
{
    protected function compare(mixed $value1, mixed $value2): int
    {
        return $value1 <=> $value2;
    }
}

$h = new H();
$h->insert(2);
$h->insert(1);
$out = [];
foreach ($h as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";

class P extends SplPriorityQueue
{
    public function compare(mixed $priority1, mixed $priority2): int
    {
        return $priority1 <=> $priority2;
    }
}

$pq = new P();
$pq->insert('a', 2);
$pq->insert('b', 1);
$seq = [];
while (!$pq->isEmpty()) {
    $seq[] = $pq->extract();
}
echo implode(',', $seq), "\n";
?>
--EXPECT--
SplHeap (value1:mixed, value2:mixed)
SplMinHeap (value1:mixed, value2:mixed)
SplMaxHeap (value1:mixed, value2:mixed)
SplPriorityQueue (priority1:mixed, priority2:mixed)
2,1
a,b
