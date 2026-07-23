--TEST--
Language: parent::$prop::set() writes parent same-name backing via child override (#22476, Zend/zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks require PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Child overrides both hooks without touching $this->x → virtual on the leaf.
// parent::$x::set() must still run P's set and write P's same-name backing (#22476).
class P {
    public string $x {
        get => $this->x;
        set(string $v) { $this->x = strtoupper($v); }
    }
}
class C extends P {
    public string $x {
        get => parent::$x::get() . '!';
        set(string $v) { parent::$x::set($v); }
    }
}
$o = new C;
$o->x = 'ab';
echo $o->x, "\n";
--EXPECT--
AB!
