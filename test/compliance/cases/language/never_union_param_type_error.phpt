--TEST--
Language: never in parameter union — compile-time fatal (#14334)
--FILE--
<?php
function f(int|never $x): void {}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: never can only be used as a standalone type in %s on line %d
