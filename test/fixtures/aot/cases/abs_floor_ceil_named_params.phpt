--TEST--
AOT: abs/floor/ceil named num: argument (#23259)
--FILE--
<?php
echo abs(num: -3), "\n";
echo floor(num: 1.5), "\n";
echo ceil(num: 1.2), "\n";
--EXPECT--
3
1
2
