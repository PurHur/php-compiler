--TEST--
Language: anonymous readonly class forward 8.4 profile (#16997, Zend/zend_compile.c)
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
    public int $x = 1;
};
echo $o->x, "\n";
try {
    $o->x = 2;
    echo "mutated\n";
} catch (Error $e) {
    echo "readonly_error\n";
}
--EXPECT--
1
readonly_error
