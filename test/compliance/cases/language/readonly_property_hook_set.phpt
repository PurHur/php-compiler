--TEST--
Language: readonly property with hooks must compile-error (#19172, re-#9835, zend_property_hooks.c)
--SKIPIF--
<?php
die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI');
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public readonly string $name {
        get => $this->name;
        set (string $value) {
            $this->name = strtoupper($value);
        }
    }
    public function __construct() {
        $this->name = 'hello';
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Hooked properties cannot be readonly
