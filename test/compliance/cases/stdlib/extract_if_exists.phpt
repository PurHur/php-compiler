--TEST--
stdlib extract() EXTR_IF_EXISTS only updates existing variables
--FILE--
<?php
$bar = 99;
extract(['foo' => 1, 'bar' => 2, 'baz' => 3], EXTR_IF_EXISTS);
echo "bar=", $bar, " baz=", $baz ?? 'undef', "\n";
--EXPECT--
bar=2 baz=undef
