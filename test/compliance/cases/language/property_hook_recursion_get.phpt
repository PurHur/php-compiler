--TEST--
Language: get-hook self-read on backed typed property — Zend uninitialized Error (#21467, re-#10005, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks require PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Hook body references $this->x → non-virtual backing slot (ReflectionProperty::isVirtual() false).
// Recursive get skips the hook and reads the uninitialized typed backing — php-src-strict.
class C {
    public string $x {
        get { return $this->x; }
    }
}
$c = new C();
try {
    echo "before\n";
    $v = $c->x;
    echo "val={$v}\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
before
Error: Typed property C::$x must not be accessed before initialization
after
