<?php

declare(strict_types=1);

$tests = [
    ['strlen', ['ab']],
    ['count', [[1, 2]]],
    ['gc_collect_cycles', []],
    ['array_sum', [[1, 2]]],
    ['is_array', [[]]],
];

foreach ($tests as [$fn, $params]) {
    try {
        $result = call_user_func($fn, ...$params);
        echo $fn, ': ok=', var_export($result, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
