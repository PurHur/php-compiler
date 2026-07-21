--TEST--
Language: new readonly class (...) ctor args with whitespace (#21885, Zend/zend_compile.c)
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

$o = new readonly class (5) {
    public function __construct(public int $x) {}
};
echo $o->x, "\n";

$bare = new readonly class {
    public int $x = 1;
};
echo $bare->x, "\n";
--EXPECT--
5
1
