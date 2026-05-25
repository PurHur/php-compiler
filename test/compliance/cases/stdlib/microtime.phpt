--TEST--
stdlib microtime() string and float forms
--FILE--
<?php
$s = microtime();
echo strlen($s) > 10 ? "parts\n" : "bad\n";
$f = microtime(true);
echo $f > 946684800 ? "float\n" : "bad\n";
--EXPECT--
parts
float
