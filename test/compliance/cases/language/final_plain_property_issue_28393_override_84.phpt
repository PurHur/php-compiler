--TEST--
Language: #28393 issue-body — child override of final public property Fatal under PROFILE=8.4
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { final public string $x = "a"; }
class B extends A { public string $x = "c"; }
echo "override_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property A::$x in %s on line %d
