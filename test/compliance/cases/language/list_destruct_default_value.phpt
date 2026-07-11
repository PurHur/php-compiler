--TEST--
Language: list destructuring slots with default values — compile-time fatal (#14325)
--FILE--
<?php
[$a = 1] = [2];
echo "ran\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Assignments can only happen to writable values in %s on line %d
