<?php
// Zend: compare throw marks heap corrupted; further insert throws "Heap is corrupted..."
// VM:   isCorrupted() stays false; further insert succeeds
class H extends SplMinHeap
{
    protected function compare($a, $b): int
    {
        if ($a === 0 || $b === 0) {
            throw new RuntimeException('boom');
        }

        return $a <=> $b;
    }
}
$h = new H();
$h->insert(1);
try {
    $h->insert(0);
    echo "inserted0\n";
} catch (Throwable $e) {
    echo 'catch1:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'corrupted:', $h->isCorrupted() ? 'yes' : 'no', "\n";
try {
    $h->insert(2);
    echo "inserted2\n";
} catch (Throwable $e) {
    echo 'catch2:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'count:', $h->count(), "\n";
