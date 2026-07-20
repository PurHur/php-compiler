--TEST--
Language: get-hook self-read on virtual property — Must not read (#21467, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../../vendor/autoload.php';
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
// Variable property access does not create a backing slot → virtual; recursive get throws.
class Y {
    public string $x {
        get {
            $name = 'x';
            return $this->$name;
        }
    }
}
$y = new Y();
try {
    echo "before\n";
    $v = $y->x;
    echo "val={$v}\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
before
Error: Must not read from virtual property Y::$x
after
