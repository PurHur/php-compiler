--TEST--
Variable variables read ($$name) (JIT)
--FILE--
<?php
$a = 'x';
$x = 42;
echo $$a, "\n";
--EXPECT--
42
