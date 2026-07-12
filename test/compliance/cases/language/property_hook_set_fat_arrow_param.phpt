--TEST--
Property hook set($param) => fat-arrow shorthand compiles and invokes on assignment (#17329, Zend/zend_compile.c)
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
declare(strict_types=1);

class Box {
    private string $stored = 'init';

    public string $x {
        get => $this->stored;
        set($v) => $this->stored = strtoupper($v);
    }
}

$box = new Box();
$box->x = 'hello';
echo $box->x, "\n";
--EXPECT--
HELLO
