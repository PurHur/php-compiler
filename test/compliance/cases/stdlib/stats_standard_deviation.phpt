--TEST--
stdlib stats_standard_deviation() — PECL stats parity (#5748, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_standard_deviation') ? "1" : "0";
echo "\n";
$data = [1.0, 2.0, 3.0, 4.0, 5.0];
$pop = stats_standard_deviation($data);
echo round($pop, 3), "\n";
$sample = stats_standard_deviation($data, true);
echo round($sample, 3), "\n";
var_export(@stats_standard_deviation([]));
echo "\n";
try {
    stats_standard_deviation('not-array');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
1
1.414
1.581
false
TypeError
