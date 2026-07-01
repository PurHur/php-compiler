<?php

declare(strict_types=1);

$fail = 0;

try {
    in_array(1, [1], true, 99);
    fwrite(STDERR, "fail: in_array excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '4 given')) {
        fwrite(STDERR, 'fail: in_array message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: in_array '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
