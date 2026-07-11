<?php

declare(strict_types=1);

$fail = 0;

try {
    number_format(1.5, 2, '.', '', 99);
    fwrite(STDERR, "fail: number_format excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '5 given')) {
        fwrite(STDERR, 'fail: number_format message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: number_format '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
