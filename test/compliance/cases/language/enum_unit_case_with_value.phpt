--TEST--
Language: unit enum case with value — compile-time fatal (#26382, Zend/zend_compile.c)
--FILE--
<?php
enum E {
    case A = 1;
}
echo "should not run\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Case A of non-backed enum E must not have a value in %s on line %d
