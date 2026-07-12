--TEST--
Language: typed function-local static variables (#17381, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTypedFunctionStatic()) {
    die('skip typed function static disabled on reference profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

function inc(): void {
    static int $n = 0;
    $n++;
    echo $n, "\n";
}
inc();
inc();

function bad(): void {
    static string $s = 'ok';
    $s = 1;
}
try { bad(); } catch (Throwable $e) { echo get_class($e), "\n"; }
--EXPECT--
1
2
TypeError
