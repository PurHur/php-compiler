--TEST--
stdlib sprintf() + sign and # alternate flags (#9058, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%+d', 5), "\n";
echo sprintf('%#x', 255), "\n";
echo sprintf('%#X', 255), "\n";
echo sprintf('%#o', 8), "\n";
echo sprintf('% d', 5), "\n";
echo sprintf('%+d', -5), "\n";
echo sprintf('%#x', 0), "\n";
--EXPECT--
+5
0xff
0XFF
010
 5
-5
0
