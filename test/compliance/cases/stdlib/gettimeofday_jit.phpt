--TEST--
stdlib gettimeofday() JIT/AOT path (#3208)
--FILE--
<?php
echo count(gettimeofday()) === 4 ? "keys\n" : "bad\n";
echo gettimeofday(true) > 946684800 ? "float\n" : "bad\n";
--EXPECT--
keys
float
