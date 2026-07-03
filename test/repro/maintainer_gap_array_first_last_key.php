<?php

declare(strict_types=1);

foreach (['array_first_key', 'array_last_key'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "missing: {$fn}\n");
        exit(1);
    }
}

$k = array_first_key([]);
echo $k === null ? "empty_first\n" : "bad_first\n";
$k = array_last_key([]);
echo $k === null ? "empty_last\n" : "bad_last\n";

$list = [10, 20, 30];
echo array_first_key($list), "\n";
echo array_last_key($list), "\n";

$assoc = ['x' => 1, 'y' => 2];
echo array_first_key($assoc), "\n";
echo array_last_key($assoc), "\n";

try {
    array_first_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    array_last_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
