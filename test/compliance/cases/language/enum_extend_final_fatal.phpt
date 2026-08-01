--TEST--
Language: class cannot extend enum — implicitly final (#26531, zend_inheritance.c / zend_enum.c)
--FILE--
<?php
enum E { case A; }
class C extends E {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class C cannot extend final class E
