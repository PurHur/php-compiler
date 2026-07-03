--TEST--
Language: promoted public private(set) — read OK, write Error (#15368, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {
    }
}
echo (new C('alice'))->name, "\n";
try {
    (new C('alice'))->name = 'bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
alice
Error: Cannot modify public private(set) property C::$name from global scope
