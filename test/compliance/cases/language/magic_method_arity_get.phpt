--TEST--
Language: magic method wrong arity — compile-time fatal (#25024, Zend/zend_API.c)
--FILE--
<?php
class G { function __get() {} }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Method G::__get() must take exactly 1 argument in %s on line %d
