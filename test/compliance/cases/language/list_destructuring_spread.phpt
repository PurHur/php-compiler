--TEST--
list destructuring with spread — [$a, ...$rest] = $arr
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsListDestructuringSpreadAssign()) {
    die('skip list spread assign disabled on reference profile');
}
?>
--FILE--
<?php
$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', implode(',', $rest), "\n";
--EXPECT--
1:2,3,4
