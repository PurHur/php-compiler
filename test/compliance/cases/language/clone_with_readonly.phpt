--TEST--
Language: clone($obj, [...]) readonly property reinit (#7250, #29187)
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
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone($c, ['x' => 2]);
echo $d->x, "\n";
--EXPECT--
2
