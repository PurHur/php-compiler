--TEST--
Language: promoted public protected(set) unparenthesized — compile fatal (#18805, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(public protected(set) string $n = 'ok') {}
}
echo (new C())->n, "\n";
--EXPECT_EXIT--
255
