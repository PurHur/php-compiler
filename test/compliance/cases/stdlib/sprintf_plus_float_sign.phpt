--TEST--
stdlib sprintf() %+ sign flag on floats (#11779, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%+d', 5), "\n";
echo sprintf('%+.2f', 1.5), "\n";
echo sprintf('%+.2f', -1.5), "\n";
echo sprintf('%+.2f', 0), "\n";
--EXPECT--
+5
+1.50
-1.50
+0.00
