--TEST--
Language: Attribute::TARGET_ALL=127 and IS_REPEATABLE=128 ConstFetch on PROFILE=8.5 (#20727)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsAttributeTargetConstant()) {
    die('skip Attribute 8.5 flag layout requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
class Rep {}
$flags = (new ReflectionClass(Rep::class))->getAttributes('Attribute')[0]->getArguments()[0];
echo $flags, "\n";
echo Attribute::TARGET_ALL, "\n";
echo Attribute::IS_REPEATABLE, "\n";
echo Attribute::TARGET_CONSTANT, "\n";
--EXPECT--
255
127
128
64
