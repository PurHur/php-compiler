<?php
declare(strict_types=1);

function expect_type_error(callable $fn, string $label): void
{
    try {
        $fn();
        echo "fail: {$label} succeeded without TypeError\n";
        exit(1);
    } catch (\TypeError $e) {
        // ok
    } catch (\Throwable $e) {
        echo "fail: {$label} threw ".get_class($e).' not TypeError: '.$e->getMessage()."\n";
        exit(1);
    }
}

expect_type_error(static fn () => get_class_methods(false), 'get_class_methods(false)');

echo "ok\n";
