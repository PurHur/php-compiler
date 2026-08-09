--TEST--
Language: clone($obj, [...]) + __clone() — overrides after __clone (#10165, #29187)
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
declare(strict_types=1);

class C {
    public int $x = 1;

    public function __clone(): void {
        $this->x = 99;
    }
}

$c = new C();
$d = clone($c, ['x' => 2]);
var_export([$c->x, $d->x]);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
