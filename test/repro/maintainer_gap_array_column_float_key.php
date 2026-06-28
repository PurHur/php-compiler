<?php
declare(strict_types=1);

function expect_type_error(callable $fn, string $label): void
{
    try {
        $fn();
        echo "fail: {$label} succeeded without TypeError\n";
        exit(1);
    } catch (\TypeError $e) {
        if (!str_contains($e->getMessage(), 'string|int|null')) {
            echo "fail: {$label} wrong message: {$e->getMessage()}\n";
            exit(1);
        }
    } catch (\Throwable $e) {
        echo "fail: {$label} threw ".get_class($e).' not TypeError: '.$e->getMessage()."\n";
        exit(1);
    }
}

expect_type_error(static fn () => array_column([['x' => 1]], 1.5), 'array_column float column_key');

echo "ok\n";
