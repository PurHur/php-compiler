--TEST--
Language: user class implements DateTimeInterface — Zend-shaped runtime fatal (#18781, Zend/zend_compile.c)
--FILE--
<?php
class UserDateTime implements DateTimeInterface {}
echo "reach\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: DateTimeInterface can't be implemented by user classes in %s on line %d
--FILE--
<?php
enum E implements DateTimeInterface { case A; }
echo "reach\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: DateTimeInterface can't be implemented by user classes in %s on line %d
