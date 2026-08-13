--TEST--
Language: typed trait constant — incompatible class override (#5993, #5953)
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
trait T { public const string FOO = 'a'; }
class C {
    use T;
    public const int FOO = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
