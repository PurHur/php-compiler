--TEST--
AOT gettimeofday() array and float (issue #3208)
--FILE--
<?php
echo count(gettimeofday()) === 4 ? "keys\n" : "bad\n";
echo gettimeofday(true) > 946684800 ? "float\n" : "bad\n";
--EXPECT--
keys
float
