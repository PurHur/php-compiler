--TEST--
stdlib memory_reset_peak_usage — exists, lowers peak, arity 0 + void (#5539, #5108, #26104)
--FILE--
<?php
echo function_exists('memory_reset_peak_usage') ? "exists\n" : "missing\n";
$peak0 = memory_get_peak_usage();
$buf = str_repeat('a', 50000);
$peak1 = memory_get_peak_usage();
unset($buf);
echo var_export(memory_reset_peak_usage(), true) . "\n";
$peak2 = memory_get_peak_usage();
$usage = memory_get_usage();
echo ($peak1 >= $peak0) ? "grew\n" : "flat\n";
echo ($peak2 <= $peak1) ? "reset_ok\n" : "reset_bad\n";
echo ($peak2 >= $usage) ? "baseline_ok\n" : "baseline_bad\n";
try {
    memory_reset_peak_usage(true);
    echo "1arg_ok\n";
} catch (ArgumentCountError $e) {
    echo "1arg:", $e->getMessage(), "\n";
}
$r = new ReflectionFunction('memory_reset_peak_usage');
echo 'arity=', $r->getNumberOfParameters(),
    ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
--EXPECT--
exists
NULL
grew
reset_ok
baseline_ok
1arg:memory_reset_peak_usage() expects exactly 0 arguments, 1 given
arity=0 return=void
