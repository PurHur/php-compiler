--TEST--
AOT: list destructuring with spread — [$a, ...$rest] = $arr (#9248)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsListDestructuringSpreadAssign()) {
    die('skip list spread assign disabled on reference profile');
}
?>
--FILE--
<?php
$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', $rest[0], ',', $rest[1], ',', $rest[2], "\n";
--EXPECT--
1:2,3,4
