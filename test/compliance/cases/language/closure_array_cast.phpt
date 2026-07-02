--TEST--
language (array) cast on Closure — one-element array with closure at index 0 (#15015, Zend/zend_closures.c)
--FILE--
<?php
$c = static fn (): int => 1;
$cast = (array) $c;
echo count($cast);
echo array_key_exists(0, $cast) ? '1' : '0';
echo $cast[0] instanceof Closure ? '1' : '0';
echo "\n";
--EXPECT--
111
