--TEST--
Property hooks short `{ get => / set => }` syntax on PHP 8.4 profile (#12941, Zend/zend_compile.c)
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
class Box {
    public int $n {
        get => $this->n ?? 0;
        set => $this->n = $value;
    }
}
$b = new Box();
$b->n = 5;
echo $b->n, "\n";
--EXPECT--
5
