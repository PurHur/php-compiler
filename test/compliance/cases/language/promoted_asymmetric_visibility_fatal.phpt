--TEST--
Language: promoted public private(set) compiles (#11546, PHP 8.4 zend_compile.c)
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
