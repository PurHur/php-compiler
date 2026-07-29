--TEST--
Language: __toString(): void — compile-time fatal (#25025)
--FILE--
<?php
class C { function __toString(): void {} }
echo "accepted\n";
--EXPECTF--
Fatal error: C::__toString(): Return type must be string when declared in %s on line %d
--EXPECT_EXIT--
255
