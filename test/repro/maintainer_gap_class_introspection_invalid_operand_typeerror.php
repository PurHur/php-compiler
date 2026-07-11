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

expect_type_error(static fn () => get_parent_class(false), 'get_parent_class(false)');
expect_type_error(static fn () => get_parent_class(1), 'get_parent_class(1)');
expect_type_error(static fn () => method_exists(false, 'x'), 'method_exists(false)');
expect_type_error(static fn () => method_exists(1, 'x'), 'method_exists(1)');
expect_type_error(static fn () => class_parents(false), 'class_parents(false)');
expect_type_error(static fn () => class_implements(false), 'class_implements(false)');
expect_type_error(static fn () => class_uses(false), 'class_uses(false)');
expect_type_error(static fn () => get_class_methods(false), 'get_class_methods(false)');

echo "ok\n";
