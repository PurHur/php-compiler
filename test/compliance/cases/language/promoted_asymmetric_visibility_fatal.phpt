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
--EXPECT_EXIT--
255
