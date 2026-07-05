--TEST--
Language: clone-with forward profile gate (#16676, PHP_COMPILER_PROFILE=8.4)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsCloneWithSyntax()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 clone-with gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Src {
    public int $x = 1;
}

$src = new Src();
$copy = clone $src with { x: 2 };
$copy2 = clone ($src, with: ['x' => 3]);
echo $copy->x, ',', $copy2->x, "\n";
--EXPECT--
2,3
