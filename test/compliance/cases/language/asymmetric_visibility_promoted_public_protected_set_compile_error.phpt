--TEST--
Language: promoted public protected(set) — compile fatal (#16195, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public protected(set) string $n = 'ok') {}
}
echo (new D())->n, "\n";
--EXPECT_EXIT--
255
