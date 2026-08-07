--TEST--
Language: eval(public final promoted) catchable CompileError under PROFILE=8.4 (#28481, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (PHPCompiler\CompilerVersion::supportsFinalPromotedProperties()) {
    die('skip PROFILE=8.4 unexpectedly enables final promotion (#28481)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    eval('class C { public function __construct(public final int $x) {} }');
    echo "accepted\n";
} catch (CompileError $e) {
    echo 'CompileError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
CompileError:Cannot use the final modifier on a parameter
after
