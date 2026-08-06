--TEST--
Language: (void) cast rejected on PROFILE=8.5 — Zend 8.5.8 ParseError (#28183, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (PHPCompiler\CompilerVersion::supportsVoidCast()) {
    die('skip PROFILE=8.5 unexpectedly enables (void) cast (#28183)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
try {
    eval('$a = (void) strlen("x");');
    echo "void_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ParseError
