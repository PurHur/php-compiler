<?php

declare(strict_types=1);

$expected = 'could not be passed by reference';

foreach (
    [
        'walk' => static fn () => array_walk((object) ['x' => 1], static fn ($v) => print($v)),
        'rec' => static fn () => array_walk_recursive((object) ['x' => 1], static fn ($v) => print($v)),
    ] as $label => $call
) {
    try {
        $call();
        echo "fail: {$label} no exception\n";
        exit(1);
    } catch (Error $e) {
        if (!str_contains($e->getMessage(), $expected)) {
            echo "fail: {$label} wrong message: {$e->getMessage()}\n";
            exit(1);
        }
        echo "{$label}=Error\n";
    }
}

$a = (object) ['x' => 1];
ob_start();
array_walk($a, static fn ($v) => print($v));
$varOut = ob_get_clean();
if ('1' !== $varOut) {
    echo "fail: variable output [{$varOut}]\n";
    exit(1);
}
echo "var=ok\n";

echo "ok\n";
