--TEST--
Language: parenthesized public (private(set)) compiles (#11546, PHP 8.4 zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip parenthesized asymmetric set modifier disabled on Zend 8.2 reference profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class Demo {
    public (private(set)) string $name = 'x';
}
echo (new Demo())->name, "\n";
--EXPECT--
x
