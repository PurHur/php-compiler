--TEST--
Language: __wakeup($a) — compile-time fatal cannot take arguments (#25023)
--FILE--
<?php
class W { function __wakeup($a = null) {} }
echo "accepted\n";
--EXPECTF--
Fatal error: Method W::__wakeup() cannot take arguments in %s on line %d
--EXPECT_EXIT--
255
