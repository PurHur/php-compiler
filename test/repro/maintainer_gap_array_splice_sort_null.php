<?php

declare(strict_types=1);

$a = [1, 2, 3, 4];
array_splice($a, -3, 2, null);
echo var_export($a, true), "\n";

foreach (['usort', 'uasort', 'uksort'] as $fn) {
    try {
        if ('usort' === $fn) {
            $arr = [1, 2];
            $fn($arr, null);
        } else {
            $arr = [1 => 2, 3 => 4];
            $fn($arr, null);
        }
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
