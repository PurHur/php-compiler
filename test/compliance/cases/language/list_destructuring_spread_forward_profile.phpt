--TEST--
Language: list destructuring spread forward profile gate (#17182, PHP_COMPILER_PROFILE=8.4)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsListDestructuringSpreadAssign()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 list spread gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', implode(',', $rest), "\n";
['label' => $label, ...$tail] = ['label' => 'L', 'a' => 1, 'b' => 2];
ksort($tail);
echo $label, "\n";
echo json_encode($tail), "\n";
--EXPECT--
1:2,3,4
L
{"a":1,"b":2}
