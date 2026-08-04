--TEST--
AOT: stats_standard_deviation() PECL stats parity (#5748)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
$data = [1.0, 2.0, 3.0, 4.0, 5.0];
echo round(stats_standard_deviation($data), 3), "\n";
echo round(stats_standard_deviation($data, true), 3), "\n";
?>
--EXPECT--
1.414
1.581
