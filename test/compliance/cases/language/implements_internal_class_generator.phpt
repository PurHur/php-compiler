--TEST--
Language: user implements Generator — runtime fatal (#18781, Zend/zend_inheritance.c)
--FILE--
<?php
echo "before\n";
class G implements Generator {}
echo "reach\n";
--EXPECTF--
before

Fatal error: G cannot implement Generator - it is not an interface in %s on line %d
--EXPECT_EXIT--
255
