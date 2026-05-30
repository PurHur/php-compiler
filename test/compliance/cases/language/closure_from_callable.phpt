--TEST--
language: Closure::fromCallable() string function (issue #3266)
--FILE--
<?php
$f = Closure::fromCallable('strlen');
echo $f('hello'), "\n";
--EXPECT--
5
