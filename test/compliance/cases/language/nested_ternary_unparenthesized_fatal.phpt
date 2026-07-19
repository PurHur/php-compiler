--TEST--
Language: unparenthesized nested ternary must fatal like Zend 7.4+ (#20737, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo true ? "a" : false ? "b" : "c";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)` in %s on line %d
