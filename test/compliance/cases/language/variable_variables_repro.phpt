--TEST--
Variable variables read/write repro (#3801)
--FILE--
<?php
$a = "hello";
$b = "a";
echo $$b, "\n";
$$c = "world";
$c = "d";
$d = "stored";
echo $$c, "\n";
--EXPECT--
hello
stored
