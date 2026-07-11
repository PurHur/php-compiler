--TEST--
Language: bare private(set) shorthand on forward profile (#16924, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare private(set) shorthand requires PHP 8.4.0+ forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C
{
    private(set) string $x = 'a';
}

$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'b';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
a
Error: Cannot modify private(set) property C::$x from global scope
