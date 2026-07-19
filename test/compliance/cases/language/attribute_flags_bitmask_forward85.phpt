--TEST--
Language: Attribute ctor bitmask IS_REPEATABLE=(1<<7) on PROFILE=8.5 (#20727)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsAttributeTargetConstant()) {
    die('skip 8.5 Attribute flag layout requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Rep {}

$args = (new ReflectionClass(Rep::class))
    ->getAttributes('Attribute')[0]
    ->getArguments();
echo $args[0], "\n";
--EXPECT--
132
