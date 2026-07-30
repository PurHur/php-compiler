--TEST--
inheritance variance: cross-eval incompatible return rejected (issue #25384)
--FILE--
<?php
class A1 { function f(): int { return 1; } }
eval('class B1 extends A1 { function f(): string { return "x"; } }');
echo "ret_accepted\n";
--EXPECTF--
PHP Fatal error:  Declaration of B1::f(): string must be compatible with A1::f(): int in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
