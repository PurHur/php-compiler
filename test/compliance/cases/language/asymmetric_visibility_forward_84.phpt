--TEST--
Language: asymmetric visibility forward 8.4 profile — bare public private(set) / public protected(set) (#18820, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare combined asymmetric modifiers require PHP 8.4 forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class PrivateSet {
    public private(set) int $x = 1;
}
echo (new PrivateSet())->x, "\n";

class ProtectedSet {
    public protected(set) string $label = 'hi';
}
echo (new ProtectedSet())->label, "\n";
--EXPECT--
1
hi
