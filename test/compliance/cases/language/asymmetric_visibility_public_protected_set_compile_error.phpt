--TEST--
Language: public protected(set) rejected at compile (#10334, PHP 8.4 zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
