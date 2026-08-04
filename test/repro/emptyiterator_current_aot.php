<?php
// Issue #27582 — EmptyIterator::current() throws under AOT.
$n = 0;
foreach (new EmptyIterator() as $v) {
    $n++;
}
echo "count=$n\n";
try {
    (new EmptyIterator())->current();
    echo "current_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
