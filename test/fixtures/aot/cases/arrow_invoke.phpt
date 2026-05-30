--TEST--
AOT: arrow function invoke (#3725)
--FILE--
<?php
$g = fn (int $x) => $x * 2;
echo $g(21), PHP_EOL;
--EXPECT--
42
