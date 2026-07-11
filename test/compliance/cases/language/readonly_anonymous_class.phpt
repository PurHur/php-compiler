--TEST--
Language: new readonly class compiles and runs (issue #6991, zend_compile.c ZEND_ACC_READONLY_ANON_CLASS)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReadonlyAnonymousClass()) {
    die('skip new readonly class requires PHP 8.3+ forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

$o = new readonly class {
    public function __construct(public int $x = 1) {}
};
echo $o->x, "\n";
--EXPECT--
1
