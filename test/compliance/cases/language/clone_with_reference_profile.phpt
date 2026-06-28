--TEST--
Language: clone-with syntax rejected on reference profile (#12987, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsCloneWithSyntax()) {
    die('skip clone-with syntax enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class Point {
    public int $x = 1;
    public int $y = 2;
}
$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
--EXPECT_EXIT--
255
