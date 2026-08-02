<?php
/**
 * Issue #7267 / #26741 — FiberStackOverflow registration + fiber stack guard (PROFILE=8.4+).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/fiber_stack_overflow.php
 */
putenv('PHP_COMPILER_PROFILE=8.4');
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
