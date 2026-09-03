<?php

/**
 * Thin AOT memory_get_usage(false) must track Native __mm__* emalloc counters (#36388).
 *
 * Zend keeps a near-constant emalloc baseline across a tiny array allocate/unset;
 * AOT previously returned the hard floor 4096 for every call. After the size-header
 * allocator, usage must rise while the array lives and fall back toward the floor
 * after unset (exact Zend pairing is not required — only monotonic honesty).
 */
$u0 = memory_get_usage(false);
$a = [];
for ($i = 0; $i < 200; $i++) {
    $a['k'.$i] = $i;
}
$u1 = memory_get_usage(false);
unset($a);
$u2 = memory_get_usage(false);

echo 'u0=', $u0, ' u1=', $u1, ' u2=', $u2, "\n";
echo ($u0 > 0 ? "floor_ok\n" : "floor_bad\n");
echo ($u1 > $u0 ? "grew_ok\n" : "grew_bad\n");
echo ($u2 < $u1 ? "freed_ok\n" : "freed_bad\n");
