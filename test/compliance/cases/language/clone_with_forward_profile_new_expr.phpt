--TEST--
Language: clone(new C(...), [...]) nested ctor first arg (#28564, PHP_COMPILER_PROFILE=8.5)
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
    public function __construct(public int $x = 1, public string $y = 'a') {}
}
$c = clone(new C(1, 'a'), ['x' => 2]);
echo $c->x, ',', $c->y, "\n";
--EXPECT--
2,a
