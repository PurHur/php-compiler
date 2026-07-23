--TEST--
Language: &$obj->hookedProp without &get Errors — Indirect modification (#22475, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class H {
    private int $n = 0;
    public int $count {
        get => $this->n;
        set(int $v) { $this->n = $v; }
    }
}
$o = new H;
try {
    $r =& $o->count;
    echo "ref_ok\n";
    $r = 5;
    echo "write=", $o->count, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

function incr(int &$x) { $x++; }
try {
    incr($o->count);
    echo "incr_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error:Indirect modification of H::$count is not allowed
Error
