--TEST--
Language: promoted constructor public private(set) rejected at compile (#10237, PHP 8.4 zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
