--TEST--
Language: clone($obj, [...]) invokes property set hooks (#7251, #29187)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsCloneWithSyntax()) {
    die('skip requires PHP_COMPILER_PROFILE=8.5 clone-with gate');
}
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires property hooks');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class C {
    public int $stored {
        get => $this->stored;
        set (int $v) { $this->stored = $v * 10; }
    }
}
$c = new C();
$c->stored = 1;
$d = clone($c, ['stored' => 2]);
echo $d->stored, "\n";
$x = $c->stored = 3;
var_export([$x, $c->stored]);
--EXPECT--
20
array (
  0 => 3,
  1 => 30,
)
