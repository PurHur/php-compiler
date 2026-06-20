--TEST--
JIT: setlocale(LC_ALL, '0') — query current locale (#10177)
--JIT--
--FILE--
<?php
setlocale(LC_ALL, 'C');
$query0 = setlocale(LC_ALL, '0');
echo is_string($query0) ? '1' : '0', "\n";
echo setlocale(LC_ALL, null), "\n";
--EXPECT--
1
C
