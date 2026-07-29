--TEST--
SplHeap: compare() throw marks isCorrupted(); further insert blocked (#24312, ext/spl/spl_heap.c)
--FILE--
<?php
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
try {
    $h->top();
    echo "top_ok\n";
} catch (Throwable $e) {
    echo 'top:', get_class($e), ':', $e->getMessage(), "\n";
}
$h->recoverFromCorruption();
echo 'after_recover:', $h->isCorrupted() ? 'yes' : 'no', "\n";
$h->insert(2);
echo 'count_after:', $h->count(), "\n";
?>
--EXPECT--
catch1:RuntimeException:boom
corrupted:yes
catch2:RuntimeException:Heap is corrupted, heap properties are no longer ensured.
count:2
top:RuntimeException:Heap is corrupted, heap properties are no longer ensured.
after_recover:no
count_after:3
