--TEST--
Language: trait constant union type mismatch — compile-time TypeError (#6905)
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
trait T {
    public const int|string C = true;
}

class C {
    use T;
}
--EXPECT_EXIT--
255
