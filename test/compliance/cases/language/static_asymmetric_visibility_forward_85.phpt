--TEST--
Language: static asymmetric visibility public private(set) static on PHP 8.5 (#26239, RFC static-aviz)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsStaticAsymmetricVisibility()) {
    die('skip static asymmetric visibility requires PHP 8.5 forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C {
    public private(set) static string $x = 'a';
    public static function setX(string $v): void { self::$x = $v; }
}
echo C::$x, "\n";
C::setX('b');
echo C::$x, "\n";
try {
    C::$x = 'c';
    echo "direct OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
a
b
Error:Cannot modify private(set) property C::$x from global scope
