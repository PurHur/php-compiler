--TEST--
Language: user implements stdClass — runtime fatal (#18781, Zend/zend_inheritance.c)
--FILE--
<?php
echo "before\n";
class S implements stdClass {
    public int $x = 1;
}
echo "reach\n";
--EXPECTF--
before

Fatal error: S cannot implement stdClass - it is not an interface in %s on line %d
--EXPECT_EXIT--
255
