--TEST--
Language: JIT class constant fetch literal, variable class, dynamic name (#6964)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDynamicClassConstFetch()) {
    die('skip dynamic class const fetch disabled on reference profile');
}
?>
--FILE--
<?php
class C {
    public const X = 1;
    public const LABEL = 'ok';
}
echo C::X, "\n";
$cls = 'C';
echo $cls::X, "\n";
$name = 'X';
echo C::{$name}, "\n";
$m = 'label';
echo C::{$m}, "\n";
--EXPECT--
1
1
1
ok
