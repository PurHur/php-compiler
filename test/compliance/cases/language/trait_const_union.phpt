--TEST--
Language: trait constants with union types int|string (#6905, zend_compile.c PHP 8.3+)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTypedTraitConstants()) {
    die('skip typed trait constants require CompilerVersion 8.3+');
}
?>
--FILE--
<?php
declare(strict_types=1);

trait T {
    public const int|string C = 1;
}

class C {
    use T;
}

echo C::C, "\n";
--EXPECT--
1
