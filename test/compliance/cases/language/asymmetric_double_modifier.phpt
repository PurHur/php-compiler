--TEST--
Language: public private(set) — compile fatal (#12088, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
%A
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
