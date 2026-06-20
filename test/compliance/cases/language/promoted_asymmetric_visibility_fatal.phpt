--TEST--
Language: promoted asymmetric visibility public private(set) rejected at compile (#10237, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
