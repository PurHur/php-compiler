--TEST--
Language: clone($obj, [...]) property overrides (PHP 8.5, #4513, #29187)
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
class C {
    public int $x = 1;
    public string $y = 'a';
}

$c = new C();
$d = clone($c, ['x' => 2, 'y' => 'b']);
var_export([$d->x, $d->y]);
--EXPECT--
array (
  0 => 2,
  1 => 'b',
)
