--TEST--
Language: readonly class / readonly property with hooks must compile-error (#19172, Zend/zend_compile.c)
--SKIPIF--
<?php
die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI');
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
readonly class R {
    public int $x {
        get => 1;
        set => $value;
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Hooked properties cannot be readonly
