--TEST--
Language: non-public __toString — compile-time fatal (#25025)
--FILE--
<?php
class A { protected function __toString() { return "x"; } }
echo "accepted\n";
--EXPECTF--
Warning: The magic method A::__toString() must have public visibility in %s on line %d
Fatal error: Access level to A::__toString() must be public (as in class Stringable) in %s on line %d
--EXPECT_EXIT--
255
