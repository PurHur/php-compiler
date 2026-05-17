--TEST--
stdlib ceil() for integers and floats
--FILE--
<?php
echo ceil(3), "\n";
echo ceil(-3), "\n";
echo ceil(2.1), "\n";
echo ceil(-2.1), "\n";
--EXPECT--
3
-3
3
-2
