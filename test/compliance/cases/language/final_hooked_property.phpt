--TEST--
Language: final hooked property `public final string $x { get => }` (#16799, Zend/zend_compile.c)
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
class C {
    public final string $label {
        get => 'ok';
    }
}
$c = new C();
echo $c->label;
--EXPECT--
ok
