--TEST--
Language: class cannot extend backed enum — implicitly final (#26531, zend_inheritance.c / zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
class C extends E {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class C cannot extend final class E
