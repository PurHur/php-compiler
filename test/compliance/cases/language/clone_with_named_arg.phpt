--TEST--
Language: clone ($obj, with: [...]) rejects named with like Zend 8.5.8 (#28182, re-#12939)
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
class Point {
    public int $x = 1;
    public int $y = 2;
}

$p = new Point();
try {
    $q = clone ($p, with: ['x' => 9]);
    echo 'OK ', $q->x, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$r = clone ($p, ['x' => 9]);
echo 'POS ', $r->x, ',', $r->y, "\n";
--EXPECT--
Error:Unknown named parameter $with
POS 9,2
