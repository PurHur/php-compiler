<?php

/**
 * Thin AOT memory_get_peak_usage / memory_reset_peak_usage vs Zend shape (#36388).
 *
 * Exact byte counts differ (arena vs emalloc); require sticky peak and reset-to-current.
 */
$u0 = memory_get_usage(false);
$p0 = memory_get_peak_usage(false);
$a = [];
for ($i = 0; $i < 500; $i++) {
    $a['k'.$i] = str_repeat('x', 64);
}
$u1 = memory_get_usage(false);
$p1 = memory_get_peak_usage(false);
unset($a);
$u2 = memory_get_usage(false);
$p2 = memory_get_peak_usage(false);
memory_reset_peak_usage();
$p3 = memory_get_peak_usage(false);
$u3 = memory_get_usage(false);

echo "u0=$u0 p0=$p0 u1=$u1 p1=$p1 u2=$u2 p2=$p2 u3=$u3 p3=$p3\n";
echo ($p1 >= $u1 ? "peak_ge_cur_ok\n" : "peak_ge_cur_bad\n");
echo ($p2 >= $p1 ? "peak_sticky_ok\n" : "peak_sticky_bad\n");
echo ($p3 <= $u3 + 1024 ? "reset_ok\n" : "reset_bad\n");
echo ($p3 <= $p2 ? "reset_le_old_ok\n" : "reset_le_old_bad\n");
