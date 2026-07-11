<?php

declare(strict_types=1);

$fail = 0;

try {
    array_chunk([1], 1, true, 99);
    fwrite(STDERR, "fail: array_chunk excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '4 given')) {
        fwrite(STDERR, 'fail: array_chunk message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: array_chunk '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
