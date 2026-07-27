<?php
// Repro #23886 — ArrayIterator accepts ArrayObject (php-src ext/spl/spl_array.c).
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
$it = new ArrayIterator($ao);
$n = 0;
$first = null;
foreach ($it as $v) {
    if (null === $first) {
        $first = $v;
    }
    $n++;
}
echo "count={$n}\n";
echo "first={$first}\n";
// Shared storage (SPL_ARRAY_USE_OTHER)
$ao['c'] = 3;
echo 'shared=', ($it->offsetExists('c') ? 'yes' : 'no'), "\n";
