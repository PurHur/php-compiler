--TEST--
Language: clone($obj, [...]) last duplicate array key wins for readonly reinit (#7250, #29187)
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
// Zend array form collapses duplicate keys; only one reinit runs.
$d = clone($c, ['x' => 2, 'x' => 3]);
echo $d->x, "\n";
--EXPECT--
3
