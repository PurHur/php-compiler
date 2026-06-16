--TEST--
stdlib sprintf() + sign and # alternate flags JIT/AOT (#9058)
--FILE--
<?php
echo sprintf('%+d', 5), "\n";
echo sprintf('%#x', 255), "\n";
echo sprintf('%#X', 255), "\n";
echo sprintf('%#o', 8), "\n";
--EXPECT--
+5
0xff
0XFF
010
