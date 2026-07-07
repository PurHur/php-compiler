--TEST--
Language: list destructuring spread rejected on reference profile (#17182, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsListDestructuringSpreadAssign()) {
    die('skip list spread assign enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', implode(',', $rest), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Spread operator is not supported in assignments in %s on line %d
