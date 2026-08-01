--TEST--
Language: magic method wrong param type — compile-time fatal (#26500, Zend/zend_API.c)
--FILE--
<?php
class G { function __get(int $n) { return 1; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: G::__get(): Parameter #1 ($n) must be of type string when declared in %s on line %d
