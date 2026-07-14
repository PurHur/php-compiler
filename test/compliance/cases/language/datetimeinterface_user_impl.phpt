--TEST--
Language: user class implements DateTimeInterface — runtime fatal (#13325, #18781, zend_compile.c)
--FILE--
<?php
class UserDateTime implements DateTimeInterface {}
echo "reach\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: DateTimeInterface can't be implemented by user classes in %s on line %d
