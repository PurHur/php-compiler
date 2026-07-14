--TEST--
Language: user class implements DateTimeInterface — runtime fatal (#18781, Zend/zend_compile.c)
--FILE--
<?php
echo "before\n";
class UserDateTime implements DateTimeInterface {}
echo "reach\n";
--EXPECTF--
before

Fatal error: DateTimeInterface can't be implemented by user classes in %s on line %d
--EXPECT_EXIT--
255
