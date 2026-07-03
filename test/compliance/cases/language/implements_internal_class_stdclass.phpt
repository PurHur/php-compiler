--TEST--
Language: user implements stdClass — compile-time fatal (#15445, Zend/zend_inheritance.c)
--FILE--
<?php
class S implements stdClass {
    public int $x = 1;
}
echo "reach\n";
--EXPECT_EXIT--
255
