--TEST--
Language: magic __call param types — name string, args array (#26500, Zend/zend_API.c)
--FILE--
<?php
class BadName { function __call(int $n, array $a) {} }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: BadName::__call(): Parameter #1 ($n) must be of type string when declared in %s on line %d
