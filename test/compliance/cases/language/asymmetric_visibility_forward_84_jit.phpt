--TEST--
Language: asymmetric visibility forward 8.4 profile JIT — unparenthesized duplicate modifiers compile fatal (#18805, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class PrivateSet {
    public private(set) int $x = 1;
}
echo (new PrivateSet())->x, "\n";

class ProtectedSet {
    public protected(set) string $label = 'hi';
}
echo (new ProtectedSet())->label, "\n";
--EXPECT_EXIT--
255
