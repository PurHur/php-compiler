--TEST--
Language: promoted public private(set) — parses and reads publicly (#14946, Zend/zend_compile.c)
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
