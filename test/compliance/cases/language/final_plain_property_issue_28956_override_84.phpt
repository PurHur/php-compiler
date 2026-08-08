--TEST--
Language: #28956 issue-body — child override of final public string Fatal under PROFILE=8.4
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { final public string $x = "a"; }
class B extends A { public string $x = "b"; }
echo "overridden\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot override final property A::$x in %s on line %d
