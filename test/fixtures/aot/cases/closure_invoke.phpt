--TEST--
AOT: anonymous closure invoke without use() (#3725)
--FILE--
<?php
$f = function (int $x): int { return $x + 1; };
echo $f(41), PHP_EOL;
--EXPECT--
42
