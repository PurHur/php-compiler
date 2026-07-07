--TEST--
list destructuring with keyed spread — JIT/AOT path (#5112, Zend/zend_execute.c)
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
$src = ['label' => 'L', 'a' => 1, 'b' => 2];
['label' => $label, ...$rest] = $src;
ksort($rest);
echo $label, "\n";
echo json_encode($rest), "\n";
--EXPECT--
L
{"a":1,"b":2}
