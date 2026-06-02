--TEST--
Union parameter type int|string compiles and runs (#4229)
--FILE--
<?php
declare(strict_types=1);
function f(int|string $x): void { echo $x, "\n"; }
f(1);
f('a');
?>
--EXPECT--
1
a
