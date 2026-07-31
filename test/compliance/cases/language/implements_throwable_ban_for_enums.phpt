--TEST--
Language: enum cannot implement Throwable (#25869, Zend/zend_exceptions.c zend_implement_throwable)
--FILE--
<?php
echo "before\n";
enum E implements Throwable { case A; }
echo "reach\n";
--EXPECTF--
before

Fatal error: Enum E cannot implement interface Throwable in %s on line %d
--EXPECT_EXIT--
255
