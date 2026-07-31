--TEST--
Language: empty class implements Throwable — ban before abstract list (#25869, Zend/zend_exceptions.c)
--FILE--
<?php
echo "before\n";
class Y implements Throwable {}
echo "reach\n";
--EXPECTF--
before

Fatal error: Class Y cannot implement interface Throwable, extend Exception or Error instead in %s on line %d
--EXPECT_EXIT--
255
