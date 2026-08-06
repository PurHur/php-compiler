--TEST--
Language: clone-with forward profile gate (#23877, #16676, PHP_COMPILER_PROFILE=8.5)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsCloneWithSyntax()) {
    die('skip requires PHP_COMPILER_PROFILE=8.5 clone-with gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
error_reporting(E_ALL);
class Src {
    public int $x = 1;
}

$src = new Src();
$copy = clone $src with { x: 2 };
$copy2 = clone ($src, ['x' => 3]);
echo $copy->x, ',', $copy2->x, "\n";
--EXPECT--
2,3
