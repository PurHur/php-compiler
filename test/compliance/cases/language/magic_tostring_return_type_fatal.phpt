--TEST--
Language: __toString(): int — compile-time fatal (#25025)
--FILE--
<?php
class B { function __toString(): int { return 1; } }
echo "accepted\n";
--EXPECTF--
Fatal error: B::__toString(): Return type must be string when declared in %s on line %d
--EXPECT_EXIT--
255
