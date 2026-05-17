--TEST--
stdlib round() for integers and floats
--FILE--
<?php
echo round(3), "\n";
echo round(-3), "\n";
echo round(2.5), "\n";
echo round(2.4), "\n";
echo round(-2.6), "\n";
--EXPECT--
3
-3
3
2
-3
