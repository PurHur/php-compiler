--TEST--
Language: promoted public private(set) compiles (#11868, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {
    }
}
echo (new C('alice'))->name, "\n";
--EXPECT--
alice
