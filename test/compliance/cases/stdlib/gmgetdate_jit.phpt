--TEST--
stdlib gmmktime() JIT/AOT path (gmgetdate phantom removed, #24608)
--FILE--
<?php
echo function_exists('gmgetdate') ? '1' : '0', "\n";
echo gmmktime(22, 13, 20, 11, 14, 2023), "\n";
--EXPECT--
0
1700000000
