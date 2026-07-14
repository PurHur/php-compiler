--TEST--
Language: __PROPERTY__ outside property hook must runtime-error on default profile (#18900, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires default profile without property hooks gate');
}
?>
--FILE--
<?php
class C {
    public function m(): void {
        echo 'outside=', __PROPERTY__, "\n";
    }
}
try {
    (new C)->m();
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
outside=Error
Undefined constant "__PROPERTY__"
