--TEST--
FiberStackOverflow class registration and fiber recursion guard (#7267, #26741)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip FiberStackOverflow requires PHP 8.4+ on Zend reference');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('PHP_COMPILER_FIBER_MAX_STACK_FRAMES=64');

echo class_exists('FiberStackOverflow', false) ? "yes\n" : "no\n";
echo is_subclass_of('FiberStackOverflow', 'Error') ? "yes\n" : "no\n";

function blow(): void {
    blow();
}

$f = new Fiber(function (): void {
    blow();
});
try {
    $f->start();
    echo "no exception\n";
} catch (FiberStackOverflow $e) {
    echo $e instanceof FiberStackOverflow ? "caught\n" : "wrong type\n";
    echo str_starts_with(
        $e->getMessage(),
        'Maximum call stack size of '
    ) ? "message ok\n" : "message bad\n";
}
--EXPECT--
yes
yes
caught
message ok
