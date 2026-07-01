<?php

declare(strict_types=1);

$fail = 0;

try {
    array_pad([1], 4, 0, STR_PAD_LEFT);
    fwrite(STDERR, "fail: array_pad excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '4 given')) {
        fwrite(STDERR, 'fail: array_pad message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: array_pad '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
