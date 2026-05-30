--TEST--
stdlib gettimeofday() array and float forms (#3208)
--FILE--
<?php
$a = gettimeofday();
echo array_key_exists('sec', $a) && array_key_exists('usec', $a)
    && array_key_exists('minuteswest', $a) && array_key_exists('dsttime', $a) ? "keys\n" : "bad\n";
echo $a['sec'] > 946684800 ? "sec\n" : "bad\n";
$f = gettimeofday(true);
echo $f > 946684800 ? "float\n" : "bad\n";
--EXPECT--
keys
sec
float
