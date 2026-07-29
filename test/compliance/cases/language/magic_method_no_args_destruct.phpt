--TEST--
Language: __destruct($a) — compile-time fatal cannot take arguments (#25023)
--FILE--
<?php
class D { function __destruct($a = null) {} }
echo "accepted\n";
--EXPECTF--
Fatal error: Method D::__destruct() cannot take arguments in %s on line %d
--EXPECT_EXIT--
255
