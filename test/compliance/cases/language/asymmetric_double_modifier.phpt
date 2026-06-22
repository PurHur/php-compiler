--TEST--
Language: public private(set) double modifier rejected at compile (#10334, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
