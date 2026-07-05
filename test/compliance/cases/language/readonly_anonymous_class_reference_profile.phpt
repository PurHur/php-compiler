--TEST--
Language: new readonly class rejected on reference profile (#16255, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsReadonlyAnonymousClass()) {
    die('skip new readonly class enabled on PHP 8.3+ forward profile');
}
?>
--FILE--
<?php
$o = new readonly class {
    public int $x = 1;
};
echo $o->x, "\n";
--EXPECT_EXIT--
255
