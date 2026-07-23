--TEST--
Language: cannot override final get property hook (#22474, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks disabled on reference profile');
}
?>
--FILE--
<?php
class P {
    public string $x {
        final get => 'p';
        set(string $v) {}
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) {}
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot override final property hook P::$x::get()
