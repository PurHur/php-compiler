--TEST--
Language: isset() on enum case expression must compile-error (#8802, Zend/zend_compile.c)
--FILE--
<?php
enum E: int {
    case A = 1;
}
var_dump(isset(E::A));
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot use isset() on the result of an expression (you can use "null !== expression" instead)
