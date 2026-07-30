<?php
// Repro #25555 — SplHeap family compare Reflection + subclass LSP (php-src spl_heap.stub.php)
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

// Named args on builtin compare (Zend stub names)
$m = new SplMinHeap();
echo (new ReflectionMethod(SplMinHeap::class, 'compare'))->invoke($m, value1: 1, value2: 2), "\n";
