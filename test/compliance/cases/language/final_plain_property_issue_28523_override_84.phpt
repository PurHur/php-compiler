--TEST--
Language: #28523 issue-body — sibling child cannot override final plain property under PROFILE=8.4
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public final int $x = 1; }
class B extends A { public int $x = 2; }
echo "OVERRIDE_OK B::x=", (new B)->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property A::$x in %s on line %d
