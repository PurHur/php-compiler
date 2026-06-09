--TEST--
stdlib memory_get_usage/gc_status/gc_mem_caches — runtime introspection (#3280)
--FILE--
<?php
foreach (['memory_get_usage', 'memory_get_peak_usage', 'gc_status', 'gc_mem_caches'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$a = str_repeat('x', 10000);
$u = memory_get_usage(true);
$p = memory_get_peak_usage(true);
echo ($u > 0 && $p >= $u) ? "memory_ok\n" : "memory_bad\n";
$st = gc_status();
echo is_array($st) ? "status_array\n" : "status_not_array\n";
echo array_key_exists('runs', $st) ? "runs_key\n" : "runs_missing\n";
echo array_key_exists('collected', $st) ? "collected_key\n" : "collected_missing\n";
echo array_key_exists('threshold', $st) ? "threshold_key\n" : "threshold_missing\n";
echo array_key_exists('roots', $st) ? "roots_key\n" : "roots_missing\n";
gc_mem_caches();
echo "mem_caches_ok\n";
--EXPECT--
memory_get_usage=yes
memory_get_peak_usage=yes
gc_status=yes
gc_mem_caches=yes
memory_ok
status_array
runs_key
collected_key
threshold_key
roots_key
mem_caches_ok
