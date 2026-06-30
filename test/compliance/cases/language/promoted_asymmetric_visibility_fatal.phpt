--TEST--
Language: promoted public private(set) — compile fatal (#13960, Zend/zend_compile.c)
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
--EXPECT_EXIT--
255
