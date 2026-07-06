--TEST--
Language: final property hook modifier `final set =>` (#16799, Zend/zend_compile.c)
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
    public string $x {
        final set => strtolower($value);
        get => $this->x ?? '';
    }
}
$c = new C();
$c->x = 'ABC';
echo $c->x;
--EXPECT--
abc
