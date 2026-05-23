--TEST--
stdlib hypot() for integers and floats
--FILE--
<?php
echo hypot(3, 4), "\n";
echo hypot(5.5, 2), "\n";
echo hypot(7, 3.5), "\n";
--EXPECT--
5
5.8523499553598
7.8262379212493
