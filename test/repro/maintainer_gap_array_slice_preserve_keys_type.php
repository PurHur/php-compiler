<?php

declare(strict_types=1);

$fail = 0;

try {
    array_slice([1], 0, 1, 99);
    fwrite(STDERR, "fail: array_slice preserve_keys int no TypeError\n");
    $fail = 1;
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'preserve_keys') || !str_contains($e->getMessage(), 'bool')) {
        fwrite(STDERR, 'fail: array_slice message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: array_slice '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
