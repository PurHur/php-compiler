<?php
/**
 * #33860 — AOT WeakMap foreach must walk entries (Zend/zend_weakrefs.c).
 */
$m = new WeakMap();
echo "empty=", 0, "\n";
foreach ($m as $k => $v) {
    echo "unexpected\n";
}

$o = new stdClass();
$m[$o] = 'v';
$n = 0;
foreach ($m as $k => $v) {
    echo get_class($k), ':', $v, "\n";
    ++$n;
}
echo "n=", $n, "\n";

function walk_33860(WeakMap $map): int
{
    $c = 0;
    foreach ($map as $k => $v) {
        ++$c;
    }

    return $c;
}
echo 'typed=', walk_33860($m), "\n";
