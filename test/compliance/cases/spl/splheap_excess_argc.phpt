--TEST--
SplHeap / SplPriorityQueue reject extra args (#30955)
--FILE--
<?php
$h = new SplMinHeap();
$h->insert(1);
foreach (['top', 'extract', 'isEmpty', 'count', 'valid', 'key', 'current', 'rewind', 'next'] as $m) {
    try {
        $h->$m(1);
        echo $m, " COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $m, ' ', $e->getMessage(), "\n";
    }
}
echo 'top_ok=', $h->top(), "\n";
echo 'count_ok=', $h->count(), "\n";
$q = new SplPriorityQueue();
$q->insert('a', 1);
try {
    $q->top(1);
    echo "pq_top COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'pq_top ', $e->getMessage(), "\n";
}
try {
    $q->compare(1, 2, 3);
    echo "pq_cmp COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'pq_cmp ', $e->getMessage(), "\n";
}
echo 'pq_top_ok=', $q->top(), "\n";
echo 'pq_cmp_ok=', $q->compare(1, 2), "\n";
?>
--EXPECT--
top SplHeap::top() expects exactly 0 arguments, 1 given
extract SplHeap::extract() expects exactly 0 arguments, 1 given
isEmpty SplHeap::isEmpty() expects exactly 0 arguments, 1 given
count SplHeap::count() expects exactly 0 arguments, 1 given
valid SplHeap::valid() expects exactly 0 arguments, 1 given
key SplHeap::key() expects exactly 0 arguments, 1 given
current SplHeap::current() expects exactly 0 arguments, 1 given
rewind SplHeap::rewind() expects exactly 0 arguments, 1 given
next SplHeap::next() expects exactly 0 arguments, 1 given
top_ok=1
count_ok=1
pq_top SplPriorityQueue::top() expects exactly 0 arguments, 1 given
pq_cmp SplPriorityQueue::compare() expects exactly 2 arguments, 3 given
pq_top_ok=a
pq_cmp_ok=-1
