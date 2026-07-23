--TEST--
Language: non-final property hook override still works (#22474)
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
        get => 'p';
        set(string $v) { $this->x = $v; }
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) { $this->x = $v; }
    }
}
echo (new C)->x, "\n";
--EXPECT--
c
