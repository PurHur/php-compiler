--TEST--
Language: __toString($x) — compile-time fatal cannot take arguments (#25029)
--FILE--
<?php
class A { public function __toString($x) { return "x"; } }
echo "accepted\n";
--EXPECTF--
Fatal error: Method A::__toString() cannot take arguments in %s on line %d
--EXPECT_EXIT--
255
