--TEST--
inheritance: child adds required parameter to concrete parent rejected at compile time (issue #6412)
--FILE--
<?php
class ParentClass { public function foo(): void {} }
class Child extends ParentClass { public function foo(int $x): void {} }
echo "should not reach\n";
--EXPECT_EXIT--
255
