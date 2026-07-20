--TEST--
Language: bare public private(set) on PHP 8.4 forward profile (#18820, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare public private(set) requires PHP 8.4 forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C {
    public private(set) string $name = 'x';
}

$c = new C();
echo $c->name, "\n";
try {
    $c->name = 'y';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
x
Error: Cannot modify private(set) property C::$name from global scope
